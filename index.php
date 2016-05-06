<?php 
/*	
	Project Name: PSTAHL - Php Static Html File Generator
	Author: Joey Albert Abano
	Open Source Resource: GITHub

	The MIT License (MIT)

	Copyright (c) 2015 Joey Albert Abano		

	Permission is hereby granted, free of charge, to any person obtaining a copy
	of this software and associated documentation files (the "Software"), to deal
	in the Software without restriction, including without limitation the rights
	to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
	copies of the Software, and to permit persons to whom the Software is
	furnished to do so, subject to the following conditions:

	The above copyright notice and this permission notice shall be included in
	all copies or substantial portions of the Software.

	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
	THE SOFTWARE.

	------------------------------------------------------------------------------------------------------------------

	It's a good thing that you started reading this section, since most likely this is the only file you'll be 
	modifying and opening. This project aims to create a simplified php static html builder

	Below are the list of the basic components and functions that this project are capable of.
	1. Uses sqlite, as a built in flat file database 
	2. Basic blog functions
	3. Standard blog development console		
	4. Export build, generate html files and directories. Create the zip file.
	5. Creating templates
	
	Dependencies
	1. PHP 5 >= 5.3.0, PHP 7
	2. jquery version 2.1.4
	3. jqueryui version 1.11.4
	4. ckeditor version 4 full editor	
	5. datatables
	6. bootstrap
	7. sqlite

	Development default directory structure
	index.php
	default.tpl.html -- default template file
	db\* -- everything is dumped on this directory
	db\cache\* -- general purpose temporary directory
	db\pstahl-sqlite.db -- database

	Export default directory structure
	index.html -- home page blog summary
	<pageno>\index.html -- paginated blog summary
	archives\index.html -- blog summary
	archives\<year-month>\index.html -- list of blogs in that year-month
	archives\<year-month>\<segment>-id\index.html

	
	Future Changes
	1. Batch blog generation. Currently blogs are generated in one go, if the blog post exceed threshold
	  it will throw memory issues.

*/


/**
 *  i. Configuration
 *  ----------------------------------------------------------------------------------------------------
 */
$_SQLITE_DATABASE_PATH = 'db/pstahl-sqlite.db';
$_ADMIN_EMAIL_LOGIN = 'z@z.z';
$_ADMIN_PASSWORD = '1';
$_PSTAHL_VERSION = '2';
$_TEMPLATE_PAGE = 'default.tpl.html';


/**
 *  ii. Request Handlers
 *  ----------------------------------------------------------------------------------------------------
 */

//-- ii.a ensures that form fields are defined, prevents cross-site scripting
$form_fields = array('action','email','password','blog-id','blog-publish-date','blog-publish-hour','blog-publish-minutes',
	'blog-publish-status','info_message','popup_message');
foreach($form_fields as $fname) {
	$_POST[$fname] = isset($_POST[$fname]) ? htmlspecialchars($_POST[$fname]) : '';
}


//-- ii.b connect to the database
$pstahldb = new PstahlSqlite();
if(!$pstahldb) {
	echo $pstahldb->lastErrorMsg();
	$_POST['popup_message'] = 'Cannot establish sqlite database connection. Please check your configuration.';
}



/**
 *  I. Controller Section
 *  ----------------------------------------------------------------------------------------------------
 */
session_start();

switch ( true ) {
	// A. login authentication
	case isset($_POST['action']) && $_POST['action']=='login-pstahl' : 
		if( $_POST['email'] == $_ADMIN_EMAIL_LOGIN && $_POST['password'] == $_ADMIN_PASSWORD ) {
			$_SESSION["user_session"] = true;	
		}
		else {
			$_POST["info_message"] = 'Invalid username and password';
		}		
		break;
	// B. signout user 
	case isset($_GET['signout']) :
		$URI = parse_url("http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]");
		unset( $_SESSION["user_session"] );
		header( 'Location: '.'http://'.$URI['host'].$URI['path'] );
		break;
	// C. create / update blog
	case isset($_SESSION["user_session"]) && isset($_POST['action']) && isset($_POST['blog-editor']) 
			&& ($_POST['action']=='save-blog'||$_POST['action']=='remove-blog') && is_valid_blog_entry() :		
		$blog = get_blog_params();
		save_blog_entry($blog);			
		break;
	// D. list blog
	case isset($_SESSION["user_session"]) && isset($_POST['action']) && $_POST['action']=='list-blog' :
		list_blog_entry();
		break;
	// E. list blog tags
	case isset($_SESSION["user_session"]) && isset($_POST['action']) && $_POST['action']=='list-tags' :
		$blog = get_blog_params();
		list_tags_blog($blog);
		break;		
	// F. get blog
	case isset($_SESSION["user_session"]) && isset($_POST['action']) && $_POST['action']=='get-blog' :
		$blog = get_blog_params();
		get_blog_entry($blog);
		break;		
	// G. export site
	case isset($_SESSION["user_session"]) && isset($_POST['action']) && $_POST['action']=='export-blog' :		
		$config = save_config();		
		export_blog($config);	
		break;		
	// H. list configuration
	case isset($_SESSION["user_session"]) && isset($_POST['action']) && $_POST['action']=='list-config' :		
		list_config();	
		break;		
	// I. save template
	case isset($_SESSION["user_session"]) && isset($_POST['action']) && $_POST['action']=='save-template' :		
		$content = get('blog-template');
		if($content != '') {
			$content = base64_decode( rawUrlDecode( $content) );
			write_file($_TEMPLATE_PAGE, $content);	
		}		
		break;	
		
}

//-- I.1 quick draw. defining the template page load on this section.
if ( isset($_SESSION["user_session"]) ) {
	$_POST['blog-template'] = read_file( $_TEMPLATE_PAGE );	
}

/**
 *  II. Database
 *  ----------------------------------------------------------------------------------------------------
 */
class PstahlSqlite extends SQLite3 {
	/*
	 * i. Initially test for database connection, and perform initialization
	 */
	function __construct() {
		global $_SQLITE_DATABASE_PATH;
		$this->open($_SQLITE_DATABASE_PATH);
		$this->busyTimeout(5000);
		$this->close();		
	}

   /*
	* ii. Generate PSTAHL tables
	*/   
   function create_pstahl_tables () {
		$sql_create_pstahl_tables =<<<EOF
			CREATE TABLE PSTAHL_INFO (
			  VERSION         INT     	  NOT NULL,
			  SITE_TITLE      TEXT,
			  CREATED_DTTM    DATETIME    NOT NULL   DEFAULT   CURRENT_TIMESTAMP,      
			  LAST_ACCESSED   DATETIME    NOT NULL );

			CREATE TABLE PSTAHL_CONFIG (
			  KEY             TEXT     	  NOT NULL,
			  VALUE           TEXT     	  NOT NULL );
			

			INSERT INTO PSTAHL_INFO (VERSION,LAST_ACCESSED) VALUES (1,CURRENT_TIMESTAMP);

			CREATE TABLE BLOG (
			  BLOG_ID             CHAR(200)         PRIMARY KEY   NOT NULL,
			  TITLE               TEXT              NOT NULL,
			  SEGMENT             TEXT              NOT NULL,
			  STATUS              CHAR(1)           NOT NULL   DEFAULT 'P',			  
			  CONTENT             TEXT,
			  CONTENT_SUMMARY     TEXT,
			  CONTENT_TYPE        CHAR(1)           NOT NULL   DEFAULT 'B',
			  CONTENT_PATH        TEXT              NULL, 
			  PUBLISH_DTTM        DATETIME,
			  CREATED_DTTM        DATETIME          NOT NULL   DEFAULT CURRENT_TIMESTAMP,
			  LAST_UPDATED_DTTM   DATETIME          NOT NULL );

			CREATE INDEX BLOG_CREATED_DTTM ON BLOG (CREATED_DTTM);
			CREATE INDEX BLOG_LAST_UPDATED_DTTM ON BLOG (LAST_UPDATED_DTTM);
			
			CREATE TABLE TAGS (
			  BLOG_ID        INT          NOT NULL,
			  TAG            CHAR(200)    NOT NULL );

			CREATE INDEX TAGS_BLOG_ID ON TAGS (BLOG_ID);
			CREATE INDEX TAGS_TAG ON TAGS (TAG);

			CREATE TABLE EXPORTS (
			  BLOG_ID        INT                   NOT NULL,
			  STATUS         CHAR(1)   DEFAULT 'R' NOT NULL,
			  EXPORT_DTTM    DATETIME );
EOF;

		$ret = $this->exec($sql_create_pstahl_tables);
	}

	/*
	 * A. Open database conenction 
	 */
	function opendb() {
		global $_SQLITE_DATABASE_PATH;		
		$this->open($_SQLITE_DATABASE_PATH);
	}

	/*
	 * B. Build database for first time access, check version.
	 */
	function initialize() {
		global $_PSTAHL_VERSION;
		$this->opendb();
		$ret = $this->query('SELECT count(name) as count FROM sqlite_master WHERE type="table" AND name in ("PSTAHL_INFO","BLOG","TAGS","EXPORTS")');
		$row = $ret->fetchArray(SQLITE3_ASSOC);
		if( $row['count'] == 0 ) {
			$this->create_pstahl_tables();
		}
		$this->query('UPDATE PSTAHL_INFO SET LAST_ACCESSED = CURRENT_TIMESTAMP WHERE VERSION = ' . $_PSTAHL_VERSION);
		$this->close();
	}	

	/*
	 * C. Create blog entry
	 */
	function create_blog($blog) {
		$this->opendb();
		$blog['BLOG_ID'] = hash('md5',$blog['TITLE'] . time());
		$sql = 'INSERT INTO BLOG (BLOG_ID,TITLE,SEGMENT,STATUS,PUBLISH_DTTM,CONTENT,CONTENT_SUMMARY,LAST_UPDATED_DTTM,CONTENT_TYPE,CONTENT_PATH) 
			VALUES (:BLOG_ID,:TITLE,:SEGMENT,:STATUS,:PUBLISH_DTTM,:CONTENT,:CONTENT_SUMMARY,CURRENT_TIMESTAMP,:CONTENT_TYPE,:CONTENT_PATH)';		
		$stmt = $this->prepare($sql);
		$stmt->bindValue(':BLOG_ID', $blog['BLOG_ID'], SQLITE3_TEXT);
		$stmt->bindValue(':TITLE', $blog['TITLE'], SQLITE3_TEXT);
		$stmt->bindValue(':SEGMENT', $blog['SEGMENT'], SQLITE3_TEXT);
		$stmt->bindValue(':STATUS', $blog['STATUS'], SQLITE3_TEXT);
		$stmt->bindValue(':PUBLISH_DTTM', $blog['PUBLISH_DTTM'], SQLITE3_TEXT);
		$stmt->bindValue(':CONTENT', $blog['CONTENT'], SQLITE3_TEXT);
		$stmt->bindValue(':CONTENT_SUMMARY', $blog['CONTENT_SUMMARY'], SQLITE3_TEXT);
		$stmt->bindValue(':CONTENT_TYPE', $blog['CONTENT_TYPE'], SQLITE3_TEXT);
		$stmt->bindValue(':CONTENT_PATH', $blog['CONTENT_PATH'], SQLITE3_TEXT);
		$result = $stmt->execute();		
		$this->close();

		$this->save_tags($blog);

		return $blog;
	}

	/*
	 * D. Update blog entry
	 */	
	function update_blog($blog) {
		$this->opendb();
		$sql = 'UPDATE BLOG SET TITLE=:TITLE,SEGMENT=:SEGMENT,STATUS=:STATUS,PUBLISH_DTTM=:PUBLISH_DTTM,
			CONTENT=:CONTENT,CONTENT_SUMMARY=:CONTENT_SUMMARY,LAST_UPDATED_DTTM=CURRENT_TIMESTAMP,CONTENT_TYPE=:CONTENT_TYPE,
			CONTENT_PATH=:CONTENT_PATH WHERE BLOG_ID=:BLOG_ID';
		$stmt = $this->prepare($sql);
		$stmt->bindValue(':BLOG_ID', $blog['BLOG_ID'], SQLITE3_TEXT);
		$stmt->bindValue(':TITLE', $blog['TITLE'], SQLITE3_TEXT);
		$stmt->bindValue(':SEGMENT', $blog['SEGMENT'], SQLITE3_TEXT);
		$stmt->bindValue(':STATUS', $blog['STATUS'], SQLITE3_TEXT);
		$stmt->bindValue(':PUBLISH_DTTM', $blog['PUBLISH_DTTM'], SQLITE3_TEXT);
		$stmt->bindValue(':CONTENT', $blog['CONTENT'], SQLITE3_TEXT);	
		$stmt->bindValue(':CONTENT_SUMMARY', $blog['CONTENT_SUMMARY'], SQLITE3_TEXT);	
		$stmt->bindValue(':CONTENT_TYPE', $blog['CONTENT_TYPE'], SQLITE3_TEXT);
		$stmt->bindValue(':CONTENT_PATH', $blog['CONTENT_PATH'], SQLITE3_TEXT);
		$result = $stmt->execute();		
		$this->close();

		$this->save_tags($blog);

		return $blog;
	}

	/*
	 * E. List all the blogs
	 */	
	function list_json_blog() {
		$this->initialize();
		$this->opendb();
		$sql = 'SELECT BLOG_ID,TITLE,SEGMENT,PUBLISH_DTTM,STATUS FROM BLOG WHERE STATUS!="R" ORDER BY DATETIME(PUBLISH_DTTM) DESC';
		$result = $this->query($sql);
		$arr = array();
		while($row = $result->fetchArray(SQLITE3_ASSOC) ) {
			array_push($arr, $row);
		}
		$json = json_encode( $arr );
		$this->close();	
    	return $json;
   }

   /*
	* F. Retreive target blog
	*/   
   function get_json_blog($blog) {
   		$this->opendb();
		$sql = 'SELECT BLOG_ID,TITLE,SEGMENT,PUBLISH_DTTM,STATUS,CONTENT,CONTENT_SUMMARY,CONTENT_TYPE,CONTENT_PATH FROM BLOG WHERE BLOG_ID=:BLOG_ID';
		$stmt = $this->prepare($sql);
		$stmt->bindValue(':BLOG_ID', $blog['BLOG_ID'], SQLITE3_TEXT);
		$result = $stmt->execute();		
		$json = json_encode( $result->fetchArray(SQLITE3_ASSOC) );
		$this->close();	
    	return $json;
   }

   /*
	* G. Save tag entries
	*/	
   function save_tags($blog) {
   		$this->opendb();
   		$sql = 'DELETE FROM TAGS WHERE BLOG_ID=:BLOG_ID';
   		$stmt = $this->prepare($sql);
		$stmt->bindValue(':BLOG_ID', $blog['BLOG_ID'], SQLITE3_TEXT);
		$result = $stmt->execute();	

		$tags = explode(',',$blog['TAGS']);

		foreach($tags as $tag) {    
			if(trim($tag) != '') {
				$sql = 'INSERT INTO TAGS (BLOG_ID,TAG) VALUES (:BLOG_ID,:TAG)';		
				$stmt = $this->prepare($sql);
				$stmt->bindValue(':BLOG_ID', $blog['BLOG_ID'], SQLITE3_TEXT);
				$stmt->bindValue(':TAG', $tag, SQLITE3_TEXT);
				$result = $stmt->execute();				    	
			}		
		}
   		$this->close();	
   }

   /*
    * H. List all the tags of a given blog
    */	   
   function list_json_tags($blog) {
		$this->opendb();
		$sql = 'SELECT TAG FROM TAGS WHERE BLOG_ID=:BLOG_ID';
		$stmt = $this->prepare($sql);
		$stmt->bindValue(':BLOG_ID', $blog['BLOG_ID'], SQLITE3_TEXT);
		$result = $stmt->execute();	
		$arr = array();
		while($row = $result->fetchArray(SQLITE3_ASSOC) ) {
			array_push($arr, $row);
		}
		$json = json_encode( $arr );
		$this->close();	
    	return $json;
   }

   /*
    * I. List config map
    */	   
   function list_config() {
   		$this->opendb();
		$sql = 'SELECT KEY,VALUE FROM PSTAHL_CONFIG';
		$result = $this->query($sql);
		$arr = array();
		while($row = $result->fetchArray(SQLITE3_ASSOC) ) {
			$arr[$row['KEY']]=$row['VALUE'];			
		}
		$this->close();	
    	return $arr;   	
   }

   /*
    * J. Save config map
    */	      
   function save_config($pstahl_config) {
   		$this->opendb();
   		$sql = 'DELETE FROM PSTAHL_CONFIG';
   		$result = $this->query($sql);

		foreach($pstahl_config as $key => $value) {    
			$sql = 'INSERT INTO PSTAHL_CONFIG (KEY,VALUE ) VALUES (:KEY,:VAL)';		
			$stmt = $this->prepare($sql);
			$stmt->bindValue(':KEY', $key, SQLITE3_TEXT);
			$stmt->bindValue(':VAL', $value, SQLITE3_TEXT);
			$result = $stmt->execute();				    
		}
   		$this->close();	

   		return $this->list_config();
   }   

}

/**
 *  III. Process Section
 *  ----------------------------------------------------------------------------------------------------
 */

/**
 *  A.1 Quickly handle POST and SESSION data
 */
function get($param,$ret_failed="") {
	switch ( true ) {
		case isset($_POST[$param]) : 
			if( gettype($_POST[$param]) == "array") {
				return $_POST[$param][0];
			}
			return $_POST[$param]; 
			break;
		case isset($_SESSION[$param]) : 
			return $_SESSION[$param]; 
			break;
		default : 
			return $ret_failed;
	}
	return $ret_failed;
}

/**
 *  A.2 Retrieve blog parameters, process data
 */
function get_blog_params() {
	$blog = array();
	$blog['BLOG_ID']=get('blog-id');
	$blog['TITLE']=get('blog-title');
	$blog['TAGS']=get('blog-tags');
	$blog['STATUS']=get('blog-publish-status');
	$blog['SEGMENT']=strtolower( preg_replace("/[^\w]+/", "-", get('blog-title')) );	

	$publishdate = '';
	if( get('blog-publish-date') != '' ) {
		$publishdate = DateTime::createFromFormat('m/d/Y', get('blog-publish-date'));
		$publishdate = $publishdate->format('Y-m-d');	
	}	

	$blog['PUBLISH_DTTM']=$publishdate . ' ' . get('blog-publish-hour') . ':' . get('blog-publish-minutes') . ':00';
	$blog['CONTENT']=get('blog-editor');
	$blog['CONTENT_SUMMARY']=get('blog-summary');
	$blog['CONTENT_TYPE']=get('blog-type');
	$blog['CONTENT_PATH']=get('blog-path');

	return $blog;
}

/**
 *  A.3 Set blog parameters in the POST 
 */
function set_blog_params($blog) {
	$_POST['blog-id']=$blog['BLOG_ID'];
	$_POST['blog-title']=$blog['TITLE'];
	$_POST['blog-publish-status']=$blog['STATUS'];
	$_POST['blog-editor']=$blog['CONTENT'];
	$_POST['blog-summary']=$blog['CONTENT_SUMMARY'];
	$_POST['blog-type']=$blog['CONTENT_TYPE'];
	$_POST['blog-path']=$blog['CONTENT_PATH'];
	
}


/**
 *  B.1 Create / Update the blog entry
 */
function save_blog_entry($blog) {	
	$db = new PstahlSqlite(); 
	if(!$db) {
		$_POST['popup_message'] = 'Cannot establish sqlite database connection. Please check your configuration.';
		return false;
	}

	if( isset($blog['BLOG_ID']) && $blog['BLOG_ID']!='' ) {
		// update blog if id already exist
		$blog = $db->update_blog($blog);
		set_blog_params($blog);	
		$_POST["info_message"] = "Post Successfully updated.";
	}
	else {
		// create blog for non-existent id
		$blog = $db->create_blog($blog);	
		set_blog_params($blog);
		$_POST["info_message"] = "Post successfully created.";		
	}		
}

/**
 *  B.2 List all blog entries as json
 */
function list_blog_entry() {
	$db = new PstahlSqlite();
	echo $db->list_json_blog();
	exit(1);
}

/**
 *  B.3 List all blog tags entries as json
 */
function list_tags_blog($blog) {
	$db = new PstahlSqlite();
	echo $db->list_json_tags($blog);
	exit(1);
}

/**
 *  B.4 Get specific blog entry
 */
function get_blog_entry($blog) {
	$db = new PstahlSqlite();		
	echo $db->get_json_blog($blog);
	exit(1);
}
	
/**
 *  B.5 List all the config 
 */	
function list_config() {
	$db = new PstahlSqlite();
	$arr = $db->list_config();
	if( !isset($arr['HEADER_TITLE']) ) {  $arr['HEADER_TITLE'] = 'Pstahl | Php Static Html Builder'; }
	if( !isset($arr['TEST_EXPORT_PATH']) ) {  $arr['TEST_EXPORT_PATH'] = 'db/cache/preview/'; }
	if( !isset($arr['TEST_BASE_URL']) ) {  $arr['TEST_BASE_URL'] = 'http://' . $_SERVER['HTTP_HOST'] . str_replace("index.php","",$_SERVER['REQUEST_URI']) . $arr['TEST_EXPORT_PATH'] ; }
	if( !isset($arr['PROD_EXPORT_PATH']) ) {  $arr['PROD_EXPORT_PATH'] = 'db/cache/prod/'; }
	if( !isset($arr['PROD_BASE_URL']) ) {  $arr['PROD_BASE_URL'] = 'http://' . $_SERVER['HTTP_HOST'] . str_replace("index.php","",$_SERVER['REQUEST_URI']) . $arr['PROD_EXPORT_PATH'] ; }
	echo json_encode( $arr );
	exit(1);
}

/**
 *  B.6 Save the given config file
 */
function save_config() {
	$config_pstahl = array();
	$config_pstahl['HEADER_TITLE']=get('html-header-title');
	$config_pstahl['TEST_EXPORT_PATH']=get('test-export-path');
	$config_pstahl['TEST_BASE_URL']=get('test-base-url');
	$config_pstahl['PROD_EXPORT_PATH']=get('prod-export-path');
	$config_pstahl['PROD_BASE_URL']=get('prod-base-url');
	$config_pstahl['IS_EXPORT_EXPLODED']=get('is-export-exploded','N');
	$config_pstahl['IS_EXPORT_COMPRESSED']=get('is-export-compressed','N');
	$config_pstahl['EXPORT_BUILD_TEST']=get('export-build-test','N');
	$config_pstahl['EXPORT_BUILD_PROD']=get('export-build-prod','N');	

	$db = new PstahlSqlite();
	if(!$db) {
		$_POST['popup_message'] = 'Cannot establish sqlite database connection. Please check your configuration.';
		return false;
	}

	return $db->save_config($config_pstahl);	
}		

/**
 *  C.1 Validate blog entry before performing save_blog_entry()
 */
function is_valid_blog_entry() {
	// pad zeros for the hour and minute fields
	$_POST['blog-publish-date'] = str_pad($_POST['blog-publish-date'], 2, "0", STR_PAD_LEFT);
	$_POST['blog-publish-hour'] = str_pad($_POST['blog-publish-hour'], 2, "0", STR_PAD_LEFT);
	$_POST['blog-publish-minutes'] = str_pad($_POST['blog-publish-minutes'], 2, "0", STR_PAD_LEFT);	

	// prepare the publish date time field
	$blogpublishdttm = $_POST['blog-publish-date'] . ' ' 
		. $_POST['blog-publish-hour'] . ':' 
		. $_POST['blog-publish-minutes'];

	// valdiate date time format
	if( !is_date_valid($blogpublishdttm) ) {
		$_POST["info_message"] = 'Invalid date time format.';	
		return FALSE;
	}

	// validate blog path
	if( get('blog-type')=='P' && get('blog-path') == '' ) {
		$_POST["info_message"] = 'Blog path cannot be empty.';	
		return FALSE;
	}


	return TRUE;
}	


/*
 *  C.2 Validate datetime string format
 */
function is_date_valid($date, $format = 'm/d/Y H:i') { // 'Y-m-d H:i:s'
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) == $date;
}

/* 
 *  D.1 Generated directory. 
 */
function generate_directory($dirpath) {
	rrmdir($dirpath); 
	mkdir($dirpath, 0700, true);
} 

/*
 *  D.2 Generate the file.
 */
function generate_file($filename,$content) {
	write_file($filename, preg_replace(array('/\s{2,}/','/[\t\n]/'),' ',$content));
}


/*
 *  D.2.1 Generate the file, extended generate_file
 */
function generate_index_file($filepath,$content) {	
	if (!is_dir($filepath)) {
		mkdir($filepath, 0700, true);
	}	
	generate_file($filepath."index.html", $content);
}

/*
 *  D.2.2 Write to a file
 */
function write_file($filename,$content) {
	$file = fopen($filename, "w") or die("Unable to open file!");	
	fwrite($file, $content);
	fclose($file);
}

/*
 *  D.3 Read file content. this method is to be used for small file sizes.
 */
function read_file($filename) {
	$read = '';
	$file = fopen($filename, "r") or die("Unable to open file!");
	// Output one line until end-of-file
	while(!feof($file)) {
		 $read = $read . fgets($file) ;
	}
	fclose($file);
	return $read;
}

/*
 *  D.4 Remove directory and file recursively.
 */
function rrmdir($dir) { 
   if (is_dir($dir)) { 
     $objects = scandir($dir); 
     foreach ($objects as $object) { 
       if ($object != "." && $object != "..") { 
         if (filetype($dir."/".$object) == "dir") rrmdir($dir."/".$object); else unlink($dir."/".$object); 
       } 
     } 
     reset($objects); 
     rmdir($dir); 
   } 
} 

/*
 *  E. Retrieve the value in an array, default to empty
 */
function getset($arr,$key) {
	return isset($arr) && is_array($arr) && array_key_exists($key,$arr) ? $arr[$key] : "";
}


function suf($str,$sufix='/') {
	if( substr($str,-1) != '/' ) {
		return $str . '/';
	}
	return $str;

}


/**
 *  IV. Export Processing
 *  ----------------------------------------------------------------------------------------------------
 */

/**
 *   A. Export site
 */
function export_blog($config) {
	// preview
	if($config['EXPORT_BUILD_TEST']=='Y') {
		export_blog_process($config,'TEST');	
	}
	// production
	if($config['EXPORT_BUILD_PROD']=='Y') {
		export_blog_process($config,'PROD');	
	}
}


/**
 *   B. Export site
 */
function export_blog_process($config,$env) {

	// i. pre-defined contants
	global $_TEMPLATE_PAGE;
	$_INX = 'index.html';
	$_BASE_URL = suf( $config[$env.'_BASE_URL'] );
	$_BASE_PATH = suf( $config[$env.'_EXPORT_PATH'] ); 
	$_PAGES_URL = $_BASE_URL.'pages/';
	$_PAGES_PATH = $_BASE_PATH.'pages/';
	$_ARCHIVES_URL = $_BASE_URL.'archives/';
	$_ARCHIVES_PATH = $_BASE_PATH.'archives/'; 
	$_ROW_COUNT = 0;	
	$_BLOG_PER_PAGE = 5;
	$_BLOG_TOTAL_PAGES = 1;
	$_PAGE_TITLE = $config['HEADER_TITLE'];
	$_ARCHIVE_TITLE = $_PAGE_TITLE . ' | archives';
	$_USE_PAGENUM = FALSE;
	$_USE_PAGEQUICK = TRUE;

	// ii. preload templates
	$_PAGE_TPL = str_replace("\$pstahl{baseurl}",$_BASE_URL,read_file($_TEMPLATE_PAGE));
	$_PAGE_TPL = str_replace("\$pstahl{title}",$_PAGE_TITLE,$_PAGE_TPL);



	// 1. run the process in the background. recommended that it is shot at an ajax request. check the status based on the db
	ignore_user_abort(true); 
	set_time_limit(0);

	// 2. extract data in the database and write in the corresponding file directories
	$db = new PstahlSqlite();
	$db->opendb();
		// 2.1 extract row count
		$sql = 'SELECT COUNT(*) AS COUNT FROM BLOG WHERE STATUS="P" AND CONTENT_TYPE="B" ';
		$result = $db->query($sql);
		$row = $result->fetchArray(SQLITE3_ASSOC);
		$_ROW_COUNT = $row['COUNT'];

		// 2.2 directory generation, identify pages
		generate_directory($_PAGES_PATH);
		generate_directory($_ARCHIVES_PATH);
		$_BLOG_TOTAL_PAGES = ceil( $_ROW_COUNT / $_BLOG_PER_PAGE );

		for($i=1;$i<=$_BLOG_TOTAL_PAGES;$i++) {
			generate_directory($_PAGES_PATH.$i."/");
		}

		// 2.3 generate blog content
		$sql = 'SELECT BLOG_ID,TITLE,SEGMENT,PUBLISH_DTTM,CONTENT,CONTENT_SUMMARY FROM BLOG WHERE STATUS="P" AND CONTENT_TYPE="B" ORDER BY DATETIME(PUBLISH_DTTM) DESC';
		$result = $db->query($sql);
		$count = 1; $curpage = 1;
		$archive_indexes = array();
		$pages_indexes = array();
		while($row = $result->fetchArray(SQLITE3_ASSOC) ) {
			// identify publish datetime, segment sufix
			list($YEAR, $MONTH, $DAY) = explode('-',explode(' ', $row['PUBLISH_DTTM'])[0]) ;
			$PUBLISHDTTM_TOTIME = strtotime("$MONTH/$DAY/$YEAR");
			$SEGMENT_SUFIX = $row['SEGMENT'] . "-" . substr(filter_var($row['BLOG_ID'], FILTER_SANITIZE_NUMBER_INT), 0, 6); 

			// generate directories and file on each segment			
			$SEGMENT_PATH = "$_ARCHIVES_PATH$YEAR/$MONTH/" . $SEGMENT_SUFIX . "/";
			$SEGMENT_URL = "$_ARCHIVES_URL$YEAR/$MONTH/" . $SEGMENT_SUFIX . "/";
			$BLOG_PATH = "$_BASE_URL$YEAR/$MONTH/" . $SEGMENT_SUFIX . "/";
			$SEGMENT_CONTENT = "<h2>".$row['TITLE']."</h2><p class=\"ui-published-date\">".date("l \of F d, Y", $PUBLISHDTTM_TOTIME)."</p><article class=\"ui-content\">".$row['CONTENT']."</article>";
			generate_directory($SEGMENT_PATH);

			$SEGMENT_CONTENT = str_replace("\$pstahl{blog.section}",$SEGMENT_CONTENT,$_PAGE_TPL);
			$SEGMENT_CONTENT = str_replace("\$pstahl{title}",$_ARCHIVE_TITLE." | ". strtolower($row['TITLE']),$SEGMENT_CONTENT);
			$SEGMENT_CONTENT = str_replace("\$pstahl{currenturl}",$SEGMENT_URL,$SEGMENT_CONTENT);
			generate_index_file($SEGMENT_PATH,$SEGMENT_CONTENT);

			// generate file on each month archive summary index			
			$MONTH_INDEX_CONTENT = "<li><a href=\"$SEGMENT_URL\"><span>".date("Y F d", $PUBLISHDTTM_TOTIME)."</span>: <span>".$row['TITLE']."</span></a></li>";
			$archive_indexes["$_ARCHIVES_PATH$YEAR/$MONTH/"] = getset($archive_indexes,"$_ARCHIVES_PATH$YEAR/$MONTH/") . $MONTH_INDEX_CONTENT;

			$YEAR_INDEX_CONTENT = "<li><a href=\"$SEGMENT_URL\"><span>".date("Y F", $PUBLISHDTTM_TOTIME)."</span>: <span>".$row['TITLE']."</span></a></li>";
			$archive_indexes["$_ARCHIVES_PATH$YEAR/"] = getset($archive_indexes,"$_ARCHIVES_PATH$YEAR/") . $YEAR_INDEX_CONTENT;

			$ARCHIVE_INDEX_CONTENT = "<li><a href=\"$SEGMENT_URL\"><span>".date("Y F", $PUBLISHDTTM_TOTIME)."</span>: <span>".$row['TITLE']."</span></a></li>";
			$archive_indexes[$_ARCHIVES_PATH] = getset($archive_indexes,$_ARCHIVES_PATH) . $ARCHIVE_INDEX_CONTENT;

			$curpage = ceil( $count / $_BLOG_PER_PAGE);
			$ENTRY_PATH = $_PAGES_PATH.$curpage."/";
			$ENTRY_CONTENT = "<h1><a href=\"$SEGMENT_URL\">".$row['TITLE']."</a></h1><p class=\"ui-published-date\">".
				date("l \of F d, Y", $PUBLISHDTTM_TOTIME)."</p><summary class=\"ui-content-summary\" >".$row['CONTENT_SUMMARY']."</summary>";
			$pages_indexes[$curpage] = getset($pages_indexes,intval($curpage)) . $ENTRY_CONTENT;

			$count++;
		}		

		// 2.4 populate the archive indexes
		foreach ($archive_indexes as $KEY => $INDEX_CONTENT) {
			$INDEX_CONTENT = "<ul class=\"ui-archive-list\">".$INDEX_CONTENT."</ul>";
			$INDEX_CONTENT = str_replace("\$pstahl{blog.section}", $INDEX_CONTENT, $_PAGE_TPL) ;
			generate_index_file($KEY,$INDEX_CONTENT);
		}

		// 2.5 populate the pages indexes
		foreach ($pages_indexes as $KEY => $INDEX_CONTENT) {
			$INDEX_CONTENT = "<p>".$INDEX_CONTENT."</p>";
			$PAGES = "";			
			if( $_USE_PAGENUM ) {
				for($i=1;$i<=$_BLOG_TOTAL_PAGES;$i++) {
					$PAGES = $PAGES . "<li><a" . ($KEY==$i ? " class=\"ui-active\"" : " href=\"$_PAGES_URL$i/\"") .">$i</a></li>";
					if($i>5 && $i<$_BLOG_TOTAL_PAGES-5) { $i = $_BLOG_TOTAL_PAGES-5; }
				}
				$PAGES = "<ul class=\"\">".$PAGES."</ul>";	
			}				

			$PAGES_QUICK = "";
			if($KEY==1 && $_BLOG_TOTAL_PAGES>1) {
				$PAGES_QUICK = "<div class=\"ui-older\"><span><a href=\"".$_PAGES_URL."2/\">Older &gt;&gt;&gt;</a></span></div>";
			}
			else if($KEY!=1 && $_BLOG_TOTAL_PAGES>1 && $KEY!=$_BLOG_TOTAL_PAGES) {
				$PAGES_QUICK = "<div class=\"ui-newer\"><span><a href=\"".$_PAGES_URL. ($KEY+1) . "/\">Older &gt;&gt;&gt;</a></span></div>" .
				"<div><span><a href=\"".$_PAGES_URL. ($KEY-1) . "/\">Newer &lt;&lt;&lt;</a></span></div>";					
			}
			else if($KEY==$_BLOG_TOTAL_PAGES) {
				$PAGES_QUICK = "<div><span><a href=\"".$_PAGES_URL. ($_BLOG_TOTAL_PAGES-1) . "/\">Newer &lt;&lt;&lt;</a></span></div>";	
			}

			if( $PAGES_QUICK!="" ) {
				$PAGES_QUICK = "<div>" . $PAGES_QUICK . "</div>";
				if( $_USE_PAGEQUICK ) {
					$PAGES = $PAGES . $PAGES_QUICK;	
				}				
			}

			$INDEX_CONTENT = $INDEX_CONTENT . $PAGES;


			$INDEX_CONTENT = str_replace("\$pstahl{blog.section}",$INDEX_CONTENT,$_PAGE_TPL);
			generate_index_file($_PAGES_PATH.$KEY."/",$INDEX_CONTENT);
		}		
		copy($_PAGES_PATH."1/index.html",$_BASE_PATH."index.html");

		// 2.6 generate pages content
		$sql = 'SELECT BLOG_ID,TITLE,SEGMENT,PUBLISH_DTTM,CONTENT,CONTENT_PATH FROM BLOG WHERE STATUS="P" AND CONTENT_TYPE="P" ORDER BY DATETIME(PUBLISH_DTTM) DESC';
		$result = $db->query($sql);
		while($row = $result->fetchArray(SQLITE3_ASSOC) ) {
			$SEGMENT_CONTENT = "<h2>".$row['TITLE']."</h2><p>".$row['CONTENT']."</p>";
			$SEGMENT_CONTENT = str_replace("\$pstahl{blog.section}",$SEGMENT_CONTENT,$_PAGE_TPL);
			$SEGMENT_CONTENT = str_replace("\$pstahl{title}",$_PAGE_TITLE." | ". strtolower($row['TITLE']),$SEGMENT_CONTENT);
			$SEGMENT_CONTENT = str_replace("\$pstahl{currenturl}",$SEGMENT_URL,$SEGMENT_CONTENT);
			generate_index_file($_BASE_PATH.suf($row['CONTENT_PATH']),$SEGMENT_CONTENT);
		}

	$db->close();	
    	
}


?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Pstahl, Php static html file generator">
    <meta name="author" content="Joey Albert Abano">
    <link rel="icon" href="//getbootstrap.com/favicon.ico">

	<title>pstahl static content development tool</title>
	<link href="//ajax.googleapis.com/ajax/libs/jqueryui/1.11.4/themes/smoothness/jquery-ui.css" rel="stylesheet" type='text/css'>
	<link href="//fonts.googleapis.com/css?family=Inconsolata:400,700" rel='stylesheet' type='text/css'>	
	<link href="//cdn.datatables.net/s/dt/dt-1.10.10/datatables.min.css" rel='stylesheet' type='text/css'/>	
	<link href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap.min.css" integrity="sha512-dTfge/zgoMYpP7QbHy4gWMEGsbsdZeCXz7irItjcC3sPUFtf0kuFbDz/ixG7ArTxmDjLXDmezHubeNikyKGVyQ==" rel="stylesheet" crossorigin="anonymous">
	<link href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap-theme.min.css" integrity="sha384-aUGj/X2zp5rLCbBxumKTCw2Z50WgIr1vs/PFN4praOTvYXWlVyh2UtNUU0KAUhAX" rel="stylesheet" crossorigin="anonymous">

	<style type="text/css">
		body,div,span,input,textarea,table,tr,td { font-family:Inconsolata, Consolas; font-weight:normal; }
		body { padding-bottom: 40px; background-color: #eee; }
		.form-signin { max-width: 330px; padding: 15px; margin: 0 auto; }
		.form-signin .form-signin-heading, .form-signin .checkbox { margin-bottom: 10px; }
		.form-signin .checkbox { font-weight: normal; }
		.form-signin .form-control { position: relative; height: auto; -webkit-box-sizing: border-box; -moz-box-sizing: border-box; box-sizing: border-box; padding: 10px; font-size: 16px; }
		.form-signin .form-control:focus { z-index: 2; }
		.form-signin input[type="email"] { margin-bottom: -1px; border-bottom-right-radius: 0; border-bottom-left-radius: 0; }
		.form-signin input[type="password"] { margin-bottom: 10px; border-top-left-radius: 0; border-top-right-radius: 0; }		

		table.ui-blog-list { width:100%;}
		
		#blog-template { height:600px; width:100%; }

		#blog-publish-date{ display:inline-block; width:88px; text-align:center; }
		#blog-publish-hour, #blog-publish-minutes { display:inline-block; width:38px; text-align:center; }
	</style>
	
	<script src="//ajax.googleapis.com/ajax/libs/jquery/2.1.4/jquery.min.js" type="text/javascript"></script>		
	<script src="//ajax.googleapis.com/ajax/libs/jqueryui/1.11.4/jquery-ui.min.js" type="text/javascript"></script>  	
	<script src="//cdn.datatables.net/s/bs/dt-1.10.10,r-2.0.0,sc-1.4.0,se-1.1.0/datatables.min.js" type="text/javascript"></script>
  	<script src="//cdn.ckeditor.com/4.5.5/standard/ckeditor.js"></script>
	<script src="//maxcdn.bootstrapcdn.com/bootstrap/3.3.5/js/bootstrap.min.js" type="text/javascript" integrity="sha512-K1qjQ+NcF2TYO/eI3M6v8EiNYZfA95pQumfvcVrTHtwQVDG+aHRqLi/ETn2uB+1JqwYqVG3LIvdm9lj6imS/pQ==" crossorigin="anonymous"></script>

	<script type="text/javascript">
	<?php if ( isset($_SESSION["user_session"]) ) : ?>

		/**
		 *  Navigation and Controls
		 */
		$(document).ready(function(){

			// 1. navigation click
			$('.nav-tabs a').click(function(){
				populate_create_form();
				if($('#blog-list-info').length) { $('#blog-list-info').remove(); }				
				$(this).tab('show');
			});

			// trigger blog list refresher
			display_blog_list({updatedttm:null,blogs:[]});		
			display_export_configuration();	

			// create blog button redirect
			$('#btn-create-blog').click(function(){
				$('.nav-create-blog a').tab('show');
				$('#blog-type').trigger('change')
			});
			// cancel blog button redirect to blog list
			$('#btn-cancel-blog').click(function(){
				$('.nav-list-blog a').tab('show');
			});
			// create date picker
			$('#blog-publish-date').datepicker();
			// help tooltoip
			$('#blog-path').tooltip({'trigger':'focus', 'title': 'This url path is appended to {base.url}'});						
			// hide blog-path if type is 'B' Blog
			$('#blog-type').change(function(){ 
				if(this.value=='B') { $('#wrap-blog-path').hide(); } 
				else { $('#wrap-blog-path').show(); } 
			});			
			$('#btn-remove-blog').click(function(){				
				$('#form-save-blog #action').val( 'remove-blog' );						
				$('#blog-publish-status').val( 'R' );			
				$('#form-save-blog').submit();
			});


			<?php if( $_POST['action']=='save-blog' ) : ?>
				$('.nav-create-blog a').tab('show');
			<?php elseif( $_POST['action']=='export-blog' ) : ?>
				$('.nav-export-blog a').tab('show');
			<?php elseif( $_POST['action']=='save-template' ) : ?>
				$('.nav-manage-template a').tab('show');
			<?php else: ?>
				$('.nav-list-blog a').tab('show');
			<?php endif; ?>
		});

		/**
		 *  1. Blog Editor.
		 *  CKEDITOR initialized textarea editor
		 */
		$(function() {			
			if( $("#blog-editor").length ===0 ) return;			
			
			CKEDITOR.replace( 'blog-editor', {
				allowedContent: true,
				extraAllowedContent: '*{*}',
			} );

			if( $("#blog-summary").length ===0 ) return;			

			CKEDITOR.replace( 'blog-summary', {
				allowedContent: true,
				extraAllowedContent: '*{*}',
			} );
			
		});			
		
		/**
		 *  2. Blog table list
		 *  
		 */
		function display_blog_list(pstahldb) {			
			var btn_html_edit = '<input type="button" value="EDIT" class="btn form-inline btn-default" onclick="$(\'.nav-create-blog a\').tab(\'show\')"/> ';
			var btn_html_remove = '<input type="button" value="REMOVE" class="btn form-inline btn-default" data-toggle="modal" data-target="#remove-blog-pstahl" /> ';
			
			$.ajax({ url: "index.php", dataType:'json', method:'POST', data:'action=list-blog', cache:false, context:document.body })
				.done(function(json) {
					// data formating
					var data = [];
					$.each(json, function(i,n) {
						n = $.map(n, function(value, index) { return [value]; });
						n[4]=String(n[4]).toUpperCase();
						n.push(btn_html_edit+btn_html_remove);
						data.push(n);
					});

					// table formatting
					var table;
					if ( $.fn.dataTable.isDataTable( '#blog-list' ) ) {
						table = $('#blog-list').DataTable();
						table.clear();
						table.rows.add(data).draw();
					}
					else {
					    table = $('#blog-list').DataTable({select:'single',data:data,order: [[ 3, "desc" ]],
					    	columns: [{title:"Id",visible:false}, {title:"Title"}, {title:"Segment"}, {title:"Publish Date/Time",width:"140px"}, 
					    	{title:"Status"}, {title:"Action", width:"134px"}]
						});
					}

					// event on row selection
					table.on('select',function( e, dt, type, indexes ){
						if(e) { e.stopPropagation(); }
						var rowData = table.rows( indexes ).data().toArray();						
						$.ajax({ url: "index.php", dataType:'json', method:'POST', data:('action=get-blog&blog-id='+rowData[0][0]), cache:false, context: document.body }).done(function(data) {
							$.ajax({ url: "index.php", dataType:'json', method:'POST', data:('action=list-tags&blog-id='+rowData[0][0]), cache:false, context: document.body }).done(function(tags) {
								console.debug(tags);
								var tag='';
								$(tags).each(function(i,e){
									tag=tag+e['TAG']+',';
								});
								populate_create_form(rowData[0][0],rowData[0][1],tag,rowData[0][3].replace(/-/g, '/'),rowData[0][4],data['CONTENT'],data['CONTENT_SUMMARY'],data['CONTENT_TYPE'],data['CONTENT_PATH']);								
								$('#blog-type').trigger('change')
							});								
							
						});					
					});
			})
			.fail( function(xhr, textStatus, errorThrown) {
        		console.error(xhr.responseText);
    		});
		}		 


		/**
		 *  3. Create / Update form population
		 */
		function populate_create_form(blogid, title, tags, publishdate, status, content, contentsummary, blogtype, blogpath) {
											
			$('#blog-id').val(blogid)
			$('#blog-title').val(title);			
			$('#blog-tags').val(tags);			
			$('#blog-type').val( !blogtype||blogtype==''?'B':blogtype );			
			$('#blog-path').val(blogpath);			
			$('#blog-publish-status').val( !status||status==''?'D':status );			

			CKEDITOR.instances['blog-editor'].setData( content&&content!='' ? content : '' ); 
			CKEDITOR.instances['blog-summary'].setData( contentsummary&&contentsummary!='' ? contentsummary : '' ); 

			d = (publishdate && publishdate!='') ? new Date( Date.parse(publishdate) ) : new Date(); console.info(publishdate,d);
			day = d.getDate();
			month = d.getMonth() + 1; //month: 0-11
			year = d.getFullYear();
			date = ("0"+month).slice(-2) + "/" + ("0"+day).slice(-2) + "/" + year;
			hours = ("0"+d.getHours()).slice(-2);
			minutes = ("0"+d.getMinutes()).slice(-2);
			seconds = d.getSeconds();
			time = hours + ":" + minutes + ":" + seconds;
			$('#blog-publish-date').val(date); $('#blog-publish-hour').val(hours);  $('#blog-publish-minutes').val(minutes);			
		}

		function display_export_configuration(){
			$.ajax({ url: "index.php", dataType:'json', method:'POST', data:('action=list-config'), cache:false, context: document.body }).done(function(configs) {
				console.debug(configs);
				populate_export_form(configs);	
				$('#preview-blog-link').attr('href',configs['TEST_BASE_URL']);
			})
			.fail( function(xhr, textStatus, errorThrown) {
        		console.error(xhr.responseText);
    		});	
		}

		function populate_export_form(configs) {
			$('#html-header-title').val(configs['HEADER_TITLE']);
			$('#test-export-path').val(configs['TEST_EXPORT_PATH']);
			$('#test-base-url').val(configs['TEST_BASE_URL']);
			$('#prod-export-path').val(configs['PROD_EXPORT_PATH']);
			$('#prod-base-url').val(configs['PROD_BASE_URL']);

			$('#is-export-exploded').attr('checked',configs['IS_EXPORT_EXPLODED']=='Y'?true:false);
			$('#is-export-compressed').attr('checked',configs['IS_EXPORT_COMPRESSED']=='Y'?true:false);
			$('#export-build-test').attr('checked',configs['EXPORT_BUILD_TEST']=='Y'?true:false);
			$('#export-build-prod').attr('checked',configs['EXPORT_BUILD_PROD']=='Y'?true:false);
		}

	<?php else: ?>
	
		/**
		 *  Authentication
		 */
		$(function() {
			// ensure that email and password are not auto-populated.
			var t = setTimeout(function(){
				$( "#in-email" ).val( "" ); 
				$( "#in-password" ).val( "" );	
			},20)						
		});
	
	<?php endif; ?>
	</script>


	
</head>
<body>

<?php if ( isset($_SESSION["user_session"]) ) : ?>

<!-- div.navbar-static-top -->
<nav class="navbar navbar-default navbar-static-top">
	<div class="container">
		<div class="navbar-header">
			<button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#navbar" aria-expanded="false" aria-controls="navbar">
			<span class="sr-only">Toggle navigation</span>
			<span class="icon-bar"></span>
			<span class="icon-bar"></span>
			<span class="icon-bar"></span>
			</button>
			<a class="navbar-brand" href="#">PSTAHL</a>
		</div>
		<div id="navbar" class="navbar-collapse collapse">			
			<ul class="nav navbar-nav navbar-right">
			<li><a>Administrator</a></li>		
			<li class="dropdown">
			<a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">Settings <span class="caret"></span></a>
			<ul class="dropdown-menu">
			<li><a href="#" data-toggle="modal" data-target="#check-updates-pstahl">Check for Update</a></li>
			<li><a href="#" data-toggle="modal" data-target="#about-pstahl">About Pstahl</a></li>
			<li role="separator" class="divider"></li>
			<li><a href="?signout=session">Sign out</a></li>
			</ul>
			</li>
			</ul>
		</div>
	</div>
</nav><!-- /.navbar-static-top -->


<!-- div.content.container -->
<div class="content container">    
	<ul class="nav nav-tabs">		
		<li class="active nav-list-blog"><a href="#list-blog">Blogs</a></li>
		<li class="nav-create-blog sr-only"><a href="#create-blog">Create Blog</a></li>		
		<li class="nav-manage-template"><a href="#manage-template">Manage Template</a></li>
		<li class="nav-export-blog"><a href="#export-blog">Export</a></li>
		<li class="nav-preview-blog"><a href="#preview-blog">Preview</a></li>
	</ul>

	<div class="tab-content">
  		<!-- #list-blog -->  		
		<div id="list-blog" class="tab-pane fade in active">	
			<h3>Blogs</h3>
			<p>
			<button id="btn-create-blog" type="button" class="btn btn-default">Create Blog</button>
			</p>
			<table id="blog-list" class="table ui-blog-list" cellpadding="0" cellspacing="0"></table>
		</div>
		<!-- /#list-blog -->

		<!-- #create-blog -->
		<div id="create-blog" class="tab-pane fade">    
			<h3>Create</h3>
			<?php if( $_POST["info_message"]!='' ):?>
				<div id="blog-list-info" class="alert alert-info"><?=$_POST["info_message"]?></div>
			<?php endif; ?>
	    	
	    	<!-- div.ui-save-blog -->
			<div id="save-blog" class="container ui-save-blog">
				<form id="form-save-blog" action="" method="POST" class="form" role="form">
				<p>		   
					<div class="row">
						<div class="form-group col-xs-4">
						  	<label for="blog-title" class="control-label">Title</label>
							<input name="blog-title" id="blog-title" type="text" value="<?=get('blog-title')?>" 
								autocomplete="off" placeholder=" Blog Title" class="form-control input-sm" required >				
						</div>
						<div class="form-group col-xs-3">
							<label for="blog-tags" class="">Tags</label>
							<input name="blog-tags" id="blog-tags" type="text" value="<?=get('blog-tags')?>" 
								autocomplete="off" placeholder=" Blog Tags" class="form-control input-sm" required >	
						</div>
						<div class="form-group col-xs-2">
							<label for="blog-publish-status">Status </label>
							<select name="blog-publish-status" id="blog-publish-status" class="form-control input-sm">
								<?php if(get('blog-publish-status')=='P'):?>
									<option value="D">Draft</option>
									<option value="P" selected="selected">Published</option>
									<option value="R">Remove</option>
								<?php else: ?>
									<option value="D" selected="selected">Draft</option>
									<option value="P">Published</option>
									<option value="R">Remove</option>
								<?php endif; ?>
							</select>						
						</div>
					</div>

					<div class="form-group form-inline">
						<label for="blog-publish-date">Publish Date</label>
						<input name="blog-publish-date" id="blog-publish-date" type="text" class="form-control input-sm" autocomplete="off" 
							value="<?=get('blog-publish-date')?>" placeholder="Date" required >			
						<input name="blog-publish-hour" id="blog-publish-hour" type="text" class="form-control input-sm" 
							value="<?=get('blog-publish-hour')?>" >
						<input name="blog-publish-minutes" id="blog-publish-minutes" type="text" class="form-control input-sm" 
							value="<?=get('blog-publish-minutes')?>">		
						<label for="blog-type">Type</label>
						<select name="blog-type" id="blog-type" class="form-control input-sm">
							<?php if(get('blog-type')=='P'):?>
								<option value="B">Blog</option>
								<option value="P" selected="selected">Page</option>								
							<?php else: ?>
								<option value="B" selected="selected">Blog</option>
								<option value="P">Page</option>
							<?php endif; ?>
						</select>
						<span id="wrap-blog-path" >
						<label for="blog-path">Blog Path</label>
						<input name="blog-path" id="blog-path" type="text" value="<?=get('blog-path')?>" 
							autocomplete="off" placeholder=" Blog Path" class="form-control input-sm" >
						</span>
					</div>
					
				</p>
				<p>
					<label for="blog-summary">Blog Summary</label>
					<textarea name="blog-summary" id="blog-summary"><?=get('blog-summary')?></textarea>
				</p>
				<p>
					<label for="blog-editor">Blog Content</label>
					<textarea name="blog-editor" id="blog-editor"><?=get('blog-editor')?></textarea>
					<input name="action" id="action" type="hidden" value="save-blog"> 
					<input name="blog-id" id="blog-id" type="hidden" value="<?=get('blog-id')?>"> 
				</p>
				<p>
					<button class="btn btn-default">Save </button>			
					<input id="btn-cancel-blog" type="button" class="btn btn-default" value="Cancel" />			
				</p>
				</form>
			</div><!-- /.ui-save-blog -->
    	</div>
    	<!-- /#create-blog -->


    	<div id="manage-template" class="tab-pane fade">
    		<h3>Manage Template</h3>
    		<p>Allow you to manage the defined site template <a href="#">[<?php echo $_TEMPLATE_PAGE; ?>]</a>.</p>
    		<div>
    			<form id="form-blog-template" action="" method="POST" role="form" >
    			<p>
    			<label for="blog-template">Blog Page Template</label>
				<textarea name="blog-template" id="blog-template"><?=get('blog-template')?></textarea>
				</p>
				<p>
    			<input type="button" class="btn btn-default" value="Save " onclick="filter_blog_template(this)" />
    			<input name="action" type="hidden" value="save-template" /> 		
    			</p>
    			</form>
    		</div>
    		<script type="text/javascript">
    			function filter_blog_template() {
    				$('#blog-template').val( encodeURIComponent( window.btoa( $('#blog-template').val() ) ) );
    				$('#form-blog-template').submit();    		
    			}
    		</script>
    	</div>


    	<!-- #export-blog -->
    	<div id="export-blog" class="tab-pane fade">
			<h3>Export</h3>
			<form action="" method="POST" class="form" role="form">
				<p>Allow you to export the blog file to a target directory.</p>
				<div class="row">
					<div class="form-group col-xs-8">				
						<label for="html-header-title">Html Header Title:</label>
						<input id="html-header-title" name="html-header-title" type="text" class="form-control" value="">
					</div>
				</div>
				<div class="row">
					<div class="form-group col-xs-4">				
						<label for="export-path-test">Test Export Path:</label>
						<input id="test-export-path" name="test-export-path" type="text" class="form-control" value="">
					</div>
					<div class="form-group col-xs-4">		
						<label for="base-url-path-test">Test Base Url Path:</label>
						<input id="test-base-url" name="test-base-url" type="text" class="form-control" value="">		
					</div>
				</div>
				<div class="row">
					<div class="form-group col-xs-4">
						<label for="export-path-prod">Production Export Path:</label>
						<input id="prod-export-path" name="prod-export-path" type="text" class="form-control" value="">
					</div>				
					<div class="form-group col-xs-4">
						<label for="base-url-path-prod">Production Base Url Path:</label>
						<input id="prod-base-url" name="prod-base-url" type="text" class="form-control" value="">
					</div>
				</div>
				<div class="form-group">
					<label for="usr">Export Flags:</label>
					<div class="checkbox">
						<label class="checkbox-inline"><input id="is-export-exploded" name="is-export-exploded" type="checkbox" value="Y" checked="checked" disabled="disabled">Exploded</label>
						<label class="checkbox-inline"><input id="is-export-compressed" name="is-export-compressed" type="checkbox" value="Y" disabled="disabled">Compressed</label>
						<label class="checkbox-inline"><input id="export-build-test" name="export-build-test" type="checkbox" value="Y">Export Preview</label>
						<label class="checkbox-inline"><input id="export-build-prod" name="export-build-prod" type="checkbox" value="Y">Export Production</label>
					</div>
				</div>				
				
				<input name="action" type="hidden" value="export-blog" /> 
				<button id="btn-export-blog" class="btn btn-default" >Export</button>
			</form>
		</div>
		<!-- /#export-blog -->

		<!-- #preview-blog -->
    	<div id="preview-blog" class="tab-pane fade">
			<h3>Preview</h3>
			<form>
				<p>Preview of the blog post.</p>
				<a id="preview-blog-link" href="#" target="_BLANK">Generated Previewed Content</iframe>
			</form>			
		</div>
		<!-- /#preview-blog -->
  </div>

</div>
<!-- /.content.container -->


<div class="modal-block">	
	<!-- Modal: Check for Updates --> 
	<div id="check-updates-pstahl" class="modal fade" role="dialog">
		<div class="modal-dialog">	    
			<div class="modal-content">
				<div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
					<h4 class="modal-title">Check for Updates</h4>
				</div>
				<div class="modal-body">
					<p>For recent builds please check the lastest changes at github <a href="https://github.com/joeyapa/pstahl/" target="_blank">https://github.com/joeyapa/</a>.</p>
				</div>
				<div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Close</button></div>
			</div>
		</div>
	</div>
	<!-- /.END Modal: Check for Updates -->
	<!-- Modal: About Pstahl --> 
	<div id="about-pstahl" class="modal fade" role="dialog">
		<div class="modal-dialog">
		    <div class="modal-content">
				<div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
					<h4 class="modal-title">About Pstahl</h4>
				</div>
				<div class="modal-body">
					<p>Pstahl is created by Joey Abano as a static html content generator. Its' main purpose is to be create a personal, lightweight, maintainable and scaleable static html blogs. </p>
				</div>
				<div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Close</button></div>
			</div>
		</div>
	</div>
	<!-- /.END Modal: About Pstahl -->	
	<!-- Modal: Delete blog --> 
	<div id="remove-blog-pstahl" class="modal fade" role="dialog">
		<div class="modal-dialog">
		    <div class="modal-content">
				<div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
					<h4 class="modal-title">Remove Blog Entry</h4>
				</div>
				<div class="modal-body">
					<p>Do you want to remove this blog entry?</p>
				</div>
				<div class="modal-footer">
					<button id="btn-remove-blog" type="button" class="btn btn-warning" data-dismiss="modal">Yes</button>
					<button type="button" class="btn btn-default" data-dismiss="modal">No</button>
				</div>
			</div>
		</div>
	</div>
	<!-- /.END Modal: Delete blog -->
</div>


<?php else: ?>
<!-- div.ui-login-wrapper -->
<div class="ui-login-wrapper container">
	<form class="form-signin" action="" method="POST">
		<?php if( get("info_message")!='' ):?>
		<div class="alert alert-warning">
			<strong>Warning!</strong> <?=get("info_message")?>
		</div>
		<?php endif; ?>
		<h2 class="form-signin-heading">Log-In</h2>
		<label for="in-email" class="sr-only">Email address</label>
		<input type="email" id="in-email" name="email" class="form-control" 
			autocomplete="off" placeholder="Email address" value="user@domain.com" required autofocus>
		<label for="in-password" class="sr-only">Password</label>
		<input type="password" id="in-password" name="password" class="form-control" 
			autocomplete="off" placeholder="Password" value="Password" required>
		<input type="hidden" id="in-action" name="action" class="form-control" value="login-pstahl">
		<div class="checkbox">
			<label><input type="checkbox" value="remember-me"> Remember me</label>
		</div>
		<button class="btn btn-lg btn-primary btn-block" type="submit">Sign in</button>
	</form>
</div>
<!-- /.ui-login-wrapper -->


<?php endif; ?>

</body>
</html>

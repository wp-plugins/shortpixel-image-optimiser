<?php
/**
 * Plugin Name: ShortPixel Image Optimizer
 * Plugin URI: https://shortpixel.com/
 * Description: ShortPixel optimizes images automatically, while guarding the quality of your images. Check your <a href="options-general.php?page=wp-shortpixel" target="_blank">Settings &gt; ShortPixel</a> page on how to start optimizing your image library and make your website load faster. 
 * Version: 3.1.5
 * Author: ShortPixel
 * Author URI: https://shortpixel.com
 */

require_once('shortpixel_api.php');
require_once('shortpixel_queue.php');
require_once('shortpixel_view.php');
require_once( ABSPATH . 'wp-admin/includes/image.php' );
include_once( ABSPATH . 'wp-admin/includes/plugin.php' ); 
if ( !is_plugin_active( 'wpmandrill/wpmandrill.php' ) && !is_plugin_active( 'wp-ses/wp-ses.php' ) ) {
  require_once( ABSPATH . 'wp-includes/pluggable.php' );//to avoid conflict with wpmandrill plugin
} 

define('SP_RESET_ON_ACTIVATE', false);

define('SP_AFFILIATE_CODE', '');

define('PLUGIN_VERSION', "3.1.5");
define('SP_MAX_TIMEOUT', 10);
define('SP_VALIDATE_MAX_TIMEOUT', 60);
define('SP_BACKUP', 'ShortpixelBackups');
define('SP_BACKUP_FOLDER', WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . SP_BACKUP);
define('MAX_API_RETRIES', 50);
$MAX_EXECUTION_TIME = ini_get('max_execution_time');

/*
 if ( is_numeric($MAX_EXECUTION_TIME)  && $MAX_EXECUTION_TIME > 10 )
    define('MAX_EXECUTION_TIME', $MAX_EXECUTION_TIME - 5 );   //in seconds
else
    define('MAX_EXECUTION_TIME', 25 );
*/

define('MAX_EXECUTION_TIME', 2 );
define("SP_MAX_RESULTS_QUERY", 6);    

class WPShortPixel {
    
    const BULK_EMPTY_QUEUE = 0;

    private $_apiKey = '';
    private $_affiliateSufix;
    private $_compressionType = 1;
    private $_processThumbnails = 1;
    private $_CMYKtoRGBconversion = 1;
    private $_backupImages = 1;
    private $_verifiedKey = false;
    
    private $_resizeImages = false;
    private $_resizeWidth = 0;
    private $_resizeHeight = 0;
    
    private $_apiInterface = null;
    private $prioQ = null;
    private $view = null;

    //handling older
    public function WPShortPixel() {
        $this->__construct();
    }

    public function __construct() {
        if (!session_id()) {
            session_start();
        }
        $this->populateOptions();
        
        $this->_affiliateSufix = (strlen(SP_AFFILIATE_CODE)) ? "/affiliate/" . SP_AFFILIATE_CODE : "";
        $this->_apiInterface = new ShortPixelAPI($this->_apiKey, $this->_compressionType, $this->_CMYKtoRGBconversion, 
                                                 $this->_resizeImages, $this->_resizeWidth, $this->_resizeHeight);
        $this->prioQ = new ShortPixelQueue($this);
        $this->view = new ShortPixelView($this);
        
        define('QUOTA_EXCEEDED', "Quota Exceeded. <a href='https://shortpixel.com/login/".$this->_apiKey."' target='_blank'>Extend Quota</a>");        
            
        $this->setDefaultViewModeList();//set default mode as list. only @ first run

        //add hook for image upload processing
        add_filter( 'wp_generate_attachment_metadata', array( &$this, 'handleImageUpload' ), 10, 2 );
        add_filter( 'manage_media_columns', array( &$this, 'columns' ) );//add media library column header
        add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array(&$this, 'generatePluginLinks'));//for plugin settings page

        //add_action( 'admin_footer', array(&$this, 'handleImageProcessing'));
        add_action( 'manage_media_custom_column', array( &$this, 'generateCustomColumn' ), 10, 2 );//generate the media library column

        //add settings page
        add_action( 'admin_menu', array( &$this, 'registerSettingsPage' ) );//display SP in Settings menu
        add_action( 'admin_menu', array( &$this, 'registerAdminPage' ) );
        add_action( 'delete_attachment', array( &$this, 'handleDeleteAttachmentInBackup' ) );
        add_action( 'load-upload.php', array( &$this, 'handleCustomBulk'));
        
        //automatic optimization
        add_action( 'wp_ajax_shortpixel_image_processing', array( &$this, 'handleImageProcessing') );
        //manual optimization
        add_action( 'wp_ajax_shortpixel_manual_optimization', array(&$this, 'handleManualOptimization'));
        //manual optimization
        add_action( 'wp_ajax_shortpixel_dismiss_notice', array(&$this, 'dismissAdminNotice'));
        //backup restore
        add_action('admin_action_shortpixel_restore_backup', array(&$this, 'handleRestoreBackup'));
        
        //This adds the constants used in PHP to be available also in JS
        add_action( 'admin_footer', array( &$this, 'shortPixelJS') );
        //register a method to display admin notices if necessary
        add_action('admin_notices', array( &$this, 'displayAdminNotices'));
        //toolbar notifications
        add_action( 'admin_bar_menu', array( &$this, 'toolbar_shortpixel_processing'), 999 );

        $this->migrateBackupFolder();
    }

    public function populateOptions() {

        $this->_apiKey = self::getOpt('wp-short-pixel-apiKey', '');
        $this->_verifiedKey = self::getOpt('wp-short-pixel-verifiedKey', $this->_verifiedKey);
        $this->_compressionType = self::getOpt('wp-short-pixel-compression', $this->_compressionType);
        $this->_processThumbnails = self::getOpt('wp-short-process_thumbnails', $this->_processThumbnails);
        $this->_CMYKtoRGBconversion = self::getOpt('wp-short-pixel_cmyk2rgb', $this->_CMYKtoRGBconversion);
        $this->_backupImages = self::getOpt('wp-short-backup_images', $this->_backupImages);
        // the following practically set defaults for options if they're not set
        self::getOpt( 'wp-short-pixel-fileCount', 0);
        self::getOpt( 'wp-short-pixel-thumbnail-count', 0);//amount of optimized thumbnails               
        self::getOpt( 'wp-short-pixel-files-under-5-percent', 0);//amount of optimized thumbnails                       
        self::getOpt( 'wp-short-pixel-savedSpace', 0);
        self::getOpt( 'wp-short-pixel-api-retries', 0);//sometimes we need to retry processing/downloading a file multiple times
        self::getOpt( 'wp-short-pixel-quota-exceeded', 0);
        self::getOpt( 'wp-short-pixel-total-original', 0);//amount of original data
        self::getOpt( 'wp-short-pixel-total-optimized', 0);//amount of optimized
        self::getOpt( 'wp-short-pixel-protocol', 'https');

        $this->_resizeImages =  self::getOpt( 'wp-short-pixel-resize-images', 0);        
        $this->_resizeWidth = self::getOpt( 'wp-short-pixel-resize-width', 0);        
        $this->_resizeHeight = self::getOpt( 'wp-short-pixel-resize-height', 0);                
    }
    
    public static function shortPixelActivatePlugin()//reset some params to avoid trouble for plugins that were activated/deactivated/activated
    {
        self::shortPixelDeactivatePlugin();
        if(SP_RESET_ON_ACTIVATE === true && WP_DEBUG === true) { //force reset plugin counters, only on specific occasions and on test environments
            delete_option('wp-short-pixel-apiKey');
            delete_option('wp-short-pixel-verifiedKey');
            delete_option('wp-short-pixel-compression');
            delete_option('wp-short-process_thumbnails');
            delete_option('wp-short-pixel_cmyk2rgb');
            delete_option('wp-short-backup_images');
            delete_option('wp-short-pixel-view-mode');
            update_option( 'wp-short-pixel-thumbnail-count', 0);
            update_option( 'wp-short-pixel-files-under-5-percent', 0);
            update_option( 'wp-short-pixel-savedSpace', 0);
            delete_option( 'wp-short-pixel-averageCompression');
            delete_option( 'wp-short-pixel-fileCount');
            delete_option( 'wp-short-pixel-total-original');
            delete_option( 'wp-short-pixel-total-optimized');
            update_option( 'wp-short-pixel-api-retries', 0);//sometimes we need to retry processing/downloading a file multiple times
            update_option( 'wp-short-pixel-quota-exceeded', 0);
            delete_option( 'wp-short-pixel-protocol');
            update_option( 'wp-short-pixel-bulk-ever-ran', 0);
            delete_option('wp-short-pixel-priorityQueue');
            delete_option( 'wp-short-pixel-resize-images');        
            delete_option( 'wp-short-pixel-resize-width');        
            delete_option( 'wp-short-pixel-resize-height');
            delete_option( 'wp-short-pixel-dismissed-notices');
            if(isset($_SESSION["wp-short-pixel-priorityQueue"])) {
                unset($_SESSION["wp-short-pixel-priorityQueue"]);
            }
            delete_option("wp-short-pixel-bulk-previous-percent");
        }
        if(!self::getOpt('wp-short-pixel-verifiedKey', false)) {
            update_option('wp-short-pixel-activation-notice', true);
        }
        update_option( 'wp-short-pixel-activation-date', time());
        delete_option( 'wp-short-pixel-bulk-last-status');
    }
    
    public static function shortPixelDeactivatePlugin()//reset some params to avoid trouble for plugins that were activated/deactivated/activated
    {
        include_once dirname( __FILE__ ) . '/shortpixel_queue.php';
        ShortPixelQueue::resetBulk();
        ShortPixelQueue::resetPrio();
        delete_option('wp-short-pixel-activation-notice');
    }    
    
    public function displayAdminNotices() {
        if(!$this->_verifiedKey) {
            $dismissed = self::getOpt( 'wp-short-pixel-dismissed-notices', array());
            $now = time();
            $act = self::getOpt( 'wp-short-pixel-activation-date', $now);
            if(self::getOpt( 'wp-short-pixel-activation-notice', false)) {
                ShortPixelView::displayActivationNotice();
                delete_option('wp-short-pixel-activation-notice');
            }
            if( ($now > $act + 7200)  && !isset($dismissed['2h'])) {
                ShortPixelView::displayActivationNotice('2h');
            } else if( ($now > $act + 72 * 3600) && !isset($dismissed['3d'])) {
                    ShortPixelView::displayActivationNotice('3d');
            }
        }
    }
    
    public function dismissAdminNotice() {
        $noticeId = preg_replace('|[^a-z0-9]|i', '', $_GET['notice_id']);
        $dismissed = self::getOpt( 'wp-short-pixel-dismissed-notices', array());
        $dismissed[$noticeId] = true;
        update_option( 'wp-short-pixel-dismissed-notices', $dismissed);
        die(json_encode(array("Status" => 'success', "Message" => 'Notice ID: ' . $noticeId . ' dismissed')));
    }        

    //set default move as "list". only set once, it won't try to set the default mode again.
    public function setDefaultViewModeList() 
    {
        if(get_option('wp-short-pixel-view-mode') === false) 
        {
            add_option('wp-short-pixel-view-mode', 1, '', 'yes' );
            if ( function_exists('get_currentuserinfo') )
                {
                    global $current_user;
                    get_currentuserinfo();
                    $currentUserID = $current_user->ID;
                    update_user_meta($currentUserID, "wp_media_library_mode", "list");
                }
        }
        
    }

    static function log($message) {
        if (WP_DEBUG === true) {
            if (is_array($message) || is_object($message)) {
                error_log(print_r($message, true));
            } else {
                error_log($message);
            }
        }
    }
   
    function shortPixelJS() { ?> 
        <script type="text/javascript" >
            jQuery(document).ready(function($){
                if(typeof ShortPixel !== 'undefined') {
                    ShortPixel.setOptions({
                        STATUS_SUCCESS: <?= ShortPixelAPI::STATUS_SUCCESS ?>,
                        STATUS_EMPTY_QUEUE: <?= self::BULK_EMPTY_QUEUE ?>,
                        STATUS_ERROR: <?= ShortPixelAPI::STATUS_ERROR ?>,
                        STATUS_FAIL: <?= ShortPixelAPI::STATUS_FAIL ?>,
                        STATUS_QUOTA_EXCEEDED: <?= ShortPixelAPI::STATUS_QUOTA_EXCEEDED ?>,
                        STATUS_SKIP: <?= ShortPixelAPI::STATUS_SKIP ?>,
                        STATUS_NO_KEY: <?= ShortPixelAPI::STATUS_NO_KEY ?>,
                        STATUS_RETRY: <?= ShortPixelAPI::STATUS_RETRY ?>,
                        WP_PLUGIN_URL: '<?= plugins_url( '', __FILE__ ) ?>',
                        API_KEY: "<?= $this->_apiKey ?>"
                    });
                }
            });
        </script> <?php
        wp_enqueue_style('short-pixel.css', plugins_url('/css/short-pixel.css',__FILE__) );
    }

    function toolbar_shortpixel_processing( $wp_admin_bar ) {
        wp_enqueue_script('short-pixel.js', plugins_url('/js/short-pixel.js',__FILE__) );
        
        $extraClasses = " shortpixel-hide";
        $tooltip = "ShortPixel optimizing...";
        $icon = "shortpixel.png";
        $successLink = $link = current_user_can( 'edit_others_posts')? 'upload.php?page=wp-short-pixel-bulk' : 'upload.php';
        $blank = "";
        if($this->prioQ->processing()) {
            $extraClasses = " shortpixel-processing";
        }
        self::log("TOOLBAR: Quota exceeded: " . self::getOpt( 'wp-short-pixel-quota-exceeded', 0));
        if(self::getOpt( 'wp-short-pixel-quota-exceeded', 0)) {
            $extraClasses = " shortpixel-alert shortpixel-quota-exceeded";
            $tooltip = "ShortPixel quota exceeded. Click to top-up";
            $link = "http://shortpixel.com/login/" . $this->_apiKey;
            $blank = '_blank';
            //$icon = "shortpixel-alert.png";
        }
        $lastStatus = self::getOpt( 'wp-short-pixel-bulk-last-status', array('Status' => ShortPixelAPI::STATUS_SUCCESS));
        if($lastStatus['Status'] != ShortPixelAPI::STATUS_SUCCESS) {
            $extraClasses = " shortpixel-alert shortpixel-processing";
            $tooltip = $lastStatus['Message'];
        }
        self::log("TB: Start:  " . $this->prioQ->getStartBulkId() . ", stop: " . $this->prioQ->getStopBulkId() . " PrioQ: "
                 .json_encode($this->prioQ->get()));

        $args = array(
                'id'    => 'shortpixel_processing',
                'title' => '<div title="' . $tooltip . '" ><img src="' 
                         . plugins_url( 'img/'.$icon, __FILE__ ) . '" success-url="' . $successLink . '"><span class="shp-alert">!</span></div>',
                'href'  => $link,
                'meta'  => array('target'=> $blank, 'class' => 'shortpixel-toolbar-processing' . $extraClasses)
        );
        $wp_admin_bar->add_node( $args );
    }

    public static function getOpt($key, $default) {
        if(get_option($key) === false) {
            add_option( $key, $default, '', 'yes' );
        }
        return get_option($key);
    }

    public function handleCustomBulk() {
        // 1. get the action
        $wp_list_table = _get_list_table('WP_Media_List_Table');
        $action = $wp_list_table->current_action();

        switch($action) {
            // 2. Perform the action
            case 'short-pixel-bulk':
                // security check
                check_admin_referer('bulk-media');
                if(!is_array($_GET['media'])) {
                    break;
                }
                $mediaIds = array_reverse($_GET['media']);
                foreach( $mediaIds as $ID ) {
                    $meta = wp_get_attachment_metadata($ID);
                    if(   (!isset($meta['ShortPixel']) || (isset($meta['ShortPixel']['WaitingProcessing']) && $meta['ShortPixel']['WaitingProcessing'] == true)) 
                       && (!isset($meta['ShortPixelImprovement']) || $meta['ShortPixelImprovement'] != 'Optimization N/A')) {
                        $this->prioQ->push($ID);
                        $meta['ShortPixel']['WaitingProcessing'] = true;
                        wp_update_attachment_metadata($ID, $meta);
                    }
                }
                break;
        }
    }

    public function handleImageUpload($meta, $ID = null)
    {
            if( !$this->_verifiedKey) {// no API Key set/verified -> do nothing here, just return
                return $meta;
            }
            //else
            self::log("IMG: Auto-analyzing file ID #{$ID}");

            if( self::isProcessable($ID) == false ) 
            {//not a file that we can process
                $meta['ShortPixelImprovement'] = 'Optimization N/A';
                return $meta;
            }
            else 
            {//the kind of file we can process. goody.
                $this->prioQ->push($ID);
                $URLsAndPATHs = $this->getURLsAndPATHs($ID, $meta);                
                $this->_apiInterface->doRequests($URLsAndPATHs['URLs'], false, $ID);//send a processing request right after a file was uploaded, do NOT wait for response   
                self::log("IMG: sent: " . json_encode($URLsAndPATHs));
                $meta['ShortPixel']['WaitingProcessing'] = true;
                return $meta;
            } 
            
    }//end handleImageUpload

    public function getCurrentBulkItemsCount(){
        global $wpdb;
        
        $startQueryID = $this->prioQ->getFlagBulkId();
        $endQueryID = $this->prioQ->getStopBulkId(); 
        
        if ( $startQueryID <= $endQueryID ) {
            return 0;
        }
        $queryPostMeta = "SELECT COUNT(DISTINCT post_id) items FROM " . $wpdb->prefix . "postmeta 
            WHERE ( post_id <= $startQueryID AND post_id > $endQueryID ) AND (
                    meta_key = '_wp_attached_file'
                    OR meta_key = '_wp_attachment_metadata' )";
        $res = $wpdb->get_results($queryPostMeta);
        return $res[0]->items;
    }
    
    public function getBulkItemsFromDb(){
        global $wpdb;
        
        $startQueryID = $this->prioQ->getStartBulkId();
        $endQueryID = $this->prioQ->getStopBulkId(); 
        $skippedAlreadyProcessed = 0;
        
        if ( $startQueryID <= $endQueryID ) {
            return false;
        }
        $idList = array();
        for ($sanityCheck = 0, $crtStartQueryID = $startQueryID;  
             $crtStartQueryID >= $endQueryID && count($idList) < 3; $sanityCheck++) {
 
            self::log("GETDB: current StartID: " . $crtStartQueryID);

            $queryPostMeta = "SELECT * FROM " . $wpdb->prefix . "postmeta 
                WHERE ( post_id <= $crtStartQueryID AND post_id >= $endQueryID ) 
                  AND ( meta_key = '_wp_attached_file' OR meta_key = '_wp_attachment_metadata' )
                ORDER BY post_id DESC
                LIMIT " . SP_MAX_RESULTS_QUERY;
            $resultsPostMeta = $wpdb->get_results($queryPostMeta);

            if ( empty($resultsPostMeta) ) {
                $crtStartQueryID -= SP_MAX_RESULTS_QUERY;
                continue;
            }

            foreach ( $resultsPostMeta as $itemMetaData ) {
                $crtStartQueryID = $itemMetaData->post_id;
                if(!in_array($crtStartQueryID, $idList) && self::isProcessable($crtStartQueryID)) {
                    $meta = wp_get_attachment_metadata($crtStartQueryID);
                    if(!isset($meta["ShortPixelImprovement"]) || !is_numeric($meta["ShortPixelImprovement"])) {
                        $idList[] = $crtStartQueryID;
                    } elseif($itemMetaData->meta_key == '_wp_attachment_metadata') { //count skipped
                        $skippedAlreadyProcessed++;
                    }
                }
            }
            if(!count($idList) && $crtStartQueryID <= $startQueryID) {
                //daca n-am adaugat niciuna pana acum, n-are sens sa mai selectez zona asta de id-uri in bulk-ul asta.
                $leapStart = $this->prioQ->getStartBulkId();
                $crtStartQueryID = $startQueryID = $itemMetaData->post_id - 1; //decrement it so we don't select it again
                $res = self::countAllProcessedFiles($leapStart, $crtStartQueryID);
                $skippedAlreadyProcessed += $res["mainFiles"]; 
                $this->prioQ->setStartBulkId($startQueryID);
            } else {
                $crtStartQueryID--;
            }
        }
        return array("ids" => $idList, "skipped" => $skippedAlreadyProcessed);
    }

    /**
     * Get last added items from priority
     * @return type
     */
    public function getFromPrioAndCheck() {
        $ids = array();
        $removeIds = array();
        
        $idsPrio = $this->prioQ->get();
        for($i = count($idsPrio) - 1, $cnt = 0; $i>=0 && $cnt < 3; $i--) {
            $id = $idsPrio[$i];
            if(wp_get_attachment_url($id)) {
                $ids[] = $id; //valid ID
            } else {
                $removeIds[] = $id;//absent, to remove
            }
        }
        foreach($removeIds as $rId){
            self::log("HIP: Unfound ID $rID Remove from Priority Queue: ".json_encode(get_option($this->prioQ->get())));
            $this->prioQ->remove($rId);
        }
        return $ids;
    }

    public function handleImageProcessing($ID = null) {
        //die("stop");
        //0: check key
        if( $this->_verifiedKey == false) {
            if($ID == null){
                $ids = $this->getFromPrioAndCheck();
                $ID = (count($ids) > 0 ? $ids[0] : null);
            }
            $response = array("Status" => ShortPixelAPI::STATUS_NO_KEY, "ImageID" => $ID, "Message" => "Missing API Key");
            update_option( 'wp-short-pixel-bulk-last-status', $response);
            die(json_encode($response));
        }
        
        self::log("HIP: 0 Priority Queue: ".json_encode($this->prioQ->get()));
        
        //1: get 3 ids to process. Take them with priority from the queue
        $ids = $this->getFromPrioAndCheck();
        if(count($ids) < 3 ) { //take from bulk if bulk processing active
            $bulkStatus = $this->prioQ->bulkRunning();
            if($bulkStatus =='running') {
                $res = $this->getBulkItemsFromDb();
                $bulkItems = $res['ids'];
                if($bulkItems){
                    $ids = array_merge ($ids, $bulkItems);
                }
            }
        }
        if ($ids === false || count( $ids ) == 0 ){
            $bulkEverRan = $this->prioQ->stopBulk();
            $avg = self::getAverageCompression();
            $fileCount = get_option('wp-short-pixel-fileCount');
            $response = array("Status" => self::BULK_EMPTY_QUEUE, 
                "Message" => 'Empty queue ' . $this->prioQ->getStartBulkId() . '->' . $this->prioQ->getStopBulkId(),
                "BulkStatus" => ($this->prioQ->bulkRunning() 
                        ? "1" : ($this->prioQ->bulkPaused() ? "2" : "0")),
                "AverageCompression" => $avg,
                "FileCount" => $fileCount,
                "BulkPercent" => $this->prioQ->getBulkPercent());
            die(json_encode($response));
        }

        self::log("HIP: 1 Prio Queue: ".json_encode($this->prioQ->get()));

        //2: Send up to 3 files to the server for processing
        for($i = 0; $i < min(3, count($ids)); $i++) {
            $ID = $ids[$i];
            $URLsAndPATHs = $this->sendToProcessing($ID);
            if($i == 0) { //save for later use
                $firstUrlAndPaths = $URLsAndPATHs;
            }
        }
        
        self::log("HIP: 2 Prio Queue: ".json_encode($this->prioQ->get()));

        //3: Retrieve the file for the first element of the list
        $ID = $ids[0];
        $result = $this->_apiInterface->processImage($firstUrlAndPaths['URLs'], $firstUrlAndPaths['PATHs'], $ID);
        $result["ImageID"] = $ID;

        self::log("HIP: 3 Prio Queue: ".json_encode($this->prioQ->get()));

        //4: update counters and priority list
        if( $result["Status"] == ShortPixelAPI::STATUS_SUCCESS) {
            self::log("HIP: Image ID $ID optimized successfully: ".json_encode($result));
            $prio = $this->prioQ->remove($ID);
            //remove also from the failed list if it failed in the past
            $prio = $this->prioQ->removeFromFailed($ID);
            $meta = wp_get_attachment_metadata($ID);
            $result["ThumbsCount"] = isset($meta['sizes']) && is_array($meta['sizes']) ? count($meta['sizes']): 0;
            $result["BackupEnabled"] = $this->_backupImages;
            
            if(!$prio && $ID <= $this->prioQ->getStartBulkId()) {
                $this->prioQ->setStartBulkId($ID - 1);
                $this->prioQ->logBulkProgress();
                
                $deltaBulkPercent = $this->prioQ->getDeltaBulkPercent(); 
                $msg = $this->bulkProgressMessage($deltaBulkPercent, $this->prioQ->getTimeRemaining());
                $result["BulkPercent"] = $this->prioQ->getBulkPercent();;
                $result["BulkMsg"] = $msg;
                
                $thumb = $bkThumb = "";
                $percent = 0;
                if(isset($meta["ShortPixelImprovement"]) && isset($meta["file"])){
                    $percent = $meta["ShortPixelImprovement"];

                    $filePath = explode("/", $meta["file"]);
                    $uploadsUrl = content_url() . "/uploads/";
                    $urlPath = implode("/", array_slice($filePath, 0, count($filePath) - 1));
                    $thumb = (isset($meta["sizes"]["medium"]) ? $meta["sizes"]["medium"]["file"] : (isset($meta["sizes"]["thumbnail"]) ? $meta["sizes"]["thumbnail"]["file"]: ""));
                    if(strlen($thumb) && get_option('wp-short-backup_images') && $this->_processThumbnails) {
                        $bkThumb = $uploadsUrl . SP_BACKUP . "/" . $urlPath . "/" . $thumb;
                    }
                    if(strlen($thumb)) {
                        $thumb = $uploadsUrl . $urlPath . "/" . $thumb;
                    }
                    $result["Thumb"] = $thumb;
                    $result["BkThumb"] = $bkThumb;
                }
            }
        }
        elseif ($result["Status"] == ShortPixelAPI::STATUS_SKIP
             || $result["Status"] == ShortPixelAPI::STATUS_FAIL) {
            $prio = $this->prioQ->remove($ID);
            if(isset($result["Code"]) && $result["Code"] == "write-fail") {
                //put this one in the failed images list - to show the user at the end
                $prio = $this->prioQ->addToFailed($ID);
            }
            if($ID <= $this->prioQ->getStartBulkId()) {
                $this->prioQ->setStartBulkId($ID - 1);
                $this->prioQ->logBulkProgress();
                $deltaBulkPercent = $this->prioQ->getDeltaBulkPercent(); 
                $msg = $this->bulkProgressMessage($deltaBulkPercent, $this->prioQ->getTimeRemaining());
                $result["BulkPercent"] = $this->prioQ->getBulkPercent();
                $result["BulkMsg"] = $msg;
            }                
        }
        update_option( 'wp-short-pixel-bulk-last-status', $result);
        die(json_encode($result));
    }
    
    private function sendToProcessing($ID) {
        $URLsAndPATHs = $this->getURLsAndPATHs($ID);
        $this->_apiInterface->doRequests($URLsAndPATHs['URLs'], false, $ID);//send a request, do NOT wait for response
        $meta = wp_get_attachment_metadata($ID);
        $meta['ShortPixel']['WaitingProcessing'] = true;
        wp_update_attachment_metadata($ID, $meta);
        return $URLsAndPATHs;
    }

    public function handleManualOptimization() {
        $imageId = intval($_GET['image_id']);
        
        if(self::isProcessable($imageId)) {
            $this->prioQ->push($imageId);
            $this->sendToProcessing($imageId);
            $ret = array("Status" => ShortPixelAPI::STATUS_SUCCESS, "message" => "");
        } else {
            die(var_dump($pathParts));            
        }
        //TODO curata functia asta
        die(json_encode($ret));

        $urlList[] = wp_get_attachment_url($attachmentID);
        $filePath[] = get_attached_file($attachmentID);
        $meta = wp_get_attachment_metadata($attachmentID);

        $processThumbnails = get_option('wp-short-process_thumbnails');

        //process all files (including thumbs)
        if($processThumbnails && !empty($meta['sizes'])) {
            //we generate an array with the URLs that need to be handled
            $SubDir = $this->_apiInterface->returnSubDir($meta['file']);
            foreach($meta['sizes'] as $thumbnailInfo) 
            {
                $urlList[]= str_replace(ShortPixelAPI::MB_basename($filePath[0]), $thumbnailInfo['file'], $urlList[0]);
                $filePath[] = str_replace(ShortPixelAPI::MB_basename($filePath[0]), $thumbnailInfo['file'], $filePath[0]);
            }
        }

        $result = $this->_apiInterface->processImage($urlList, $filePath, $attachmentID);//request to process all the images

        if ( !is_array($result) )//there was an error, we save it in ShortPixelImprovement data
            $this->handleError($attachmentID, $result);

        // store the referring webpage location
        $sendback = wp_get_referer();
        // sanitize the referring webpage location
        $sendback = preg_replace('|[^a-z0-9-~+_.?#=&;,/:]|i', '', $sendback);
        // send the user back where they came from
        wp_redirect($sendback);
        // we are done,
    }
    
    //save error in file's meta data
    public function handleError($ID, $result)
    {
        $meta = wp_get_attachment_metadata($ID);
        $meta['ShortPixelImprovement'] = $result;
        wp_update_attachment_metadata($ID, $meta);
    }

    public function handleRestoreBackup() {
        $attachmentID = intval($_GET['attachment_ID']);

        $file = get_attached_file($attachmentID);
        $meta = wp_get_attachment_metadata($attachmentID);
        $pathInfo = pathinfo($file);
    
        $fileExtension = strtolower(substr($file,strrpos($file,".")+1));
        $SubDir = $this->_apiInterface->returnSubDir($file);

        //sometimes the month of original file and backup can differ
        if ( !file_exists(SP_BACKUP_FOLDER . DIRECTORY_SEPARATOR . $SubDir . ShortPixelAPI::MB_basename($file)) )
            $SubDir = date("Y") . "/" . date("m") . "/";

        try {
            //main file    
            @rename(SP_BACKUP_FOLDER . DIRECTORY_SEPARATOR . $SubDir . ShortPixelAPI::MB_basename($file), $file);

            //overwriting thumbnails
            if( !empty($meta['file']) ) {
                foreach($meta["sizes"] as $size => $imageData) {
                    $source = SP_BACKUP_FOLDER . DIRECTORY_SEPARATOR . $SubDir . $imageData['file'];
                    $destination = $pathInfo['dirname'] . DIRECTORY_SEPARATOR . $imageData['file'];
                    @rename($source, $destination);
                }
            }
            unset($meta["ShortPixelImprovement"]);
            unset($meta['ShortPixel']['WaitingProcessing']);
            wp_update_attachment_metadata($attachmentID, $meta);

        } catch(Exception $e) {
            //what to do, what to do?
        }
        // store the referring webpage location
        $sendback = wp_get_referer();
        // sanitize the referring webpage location
        $sendback = preg_replace('|[^a-z0-9-~+_.?#=&;,/:]|i', '', $sendback);
        // send the user back where they came from
        wp_redirect($sendback);
        // we are done
    }


    public function handleDeleteAttachmentInBackup($ID) {
        $file = get_attached_file($ID);
        $meta = wp_get_attachment_metadata($ID);
        
        if(self::isProcessable($ID) != false) 
        {
            $SubDir = $this->_apiInterface->returnSubDir($file);  
            try {
                    $SubDir = $this->_apiInterface->returnSubDir($file);
                        
                    @unlink(SP_BACKUP_FOLDER . DIRECTORY_SEPARATOR . $SubDir . ShortPixelAPI::MB_basename($file));
                    
                    if ( !empty($meta['file']) )
                    {
                        $filesPath =  SP_BACKUP_FOLDER . DIRECTORY_SEPARATOR . $SubDir;//base BACKUP path
                        //remove thumbs thumbnails
                        if(isset($meta["sizes"])) {
                            foreach($meta["sizes"] as $size => $imageData) {
                                @unlink($filesPath . ShortPixelAPI::MB_basename($imageData['file']));//remove thumbs
                            }
                        }
                    }            
                
                } catch(Exception $e) {
                //what to do, what to do?
            }
        }
    }

    public function registerSettingsPage() {
        add_options_page( 'ShortPixel Settings', 'ShortPixel', 'manage_options', 'wp-shortpixel', array($this, 'renderSettingsMenu'));
    }

    function registerAdminPage( ) {
        add_media_page( 'ShortPixel Bulk Process', 'Bulk ShortPixel', 'edit_others_posts', 'wp-short-pixel-bulk', array( &$this, 'bulkProcess' ) );
    }
    
    public function checkQuotaAndAlert() {
        $quotaData = $this->getQuotaInformation();
        if ( !$quotaData['APIKeyValid']) {
            return $quotaData;
        }
        $imageCount = $this->countAllProcessableFiles();
        $imageProcessedCount = $this->countAllProcessedFiles();
        $quotaData['totalFiles'] = $imageCount['totalFiles'];
        $quotaData['totalProcessedFiles'] = $imageProcessedCount['totalFiles'];
        $quotaData['mainFiles'] = $imageCount['mainFiles'];
        $quotaData['mainProcessedFiles'] = $imageProcessedCount['mainFiles'];

        if($quotaData['APICallsQuotaNumeric'] + $quotaData['APICallsQuotaOneTimeNumeric'] > $quotaData['APICallsMadeNumeric'] + $quotaData['APICallsMadeOneTimeNumeric']) {
            update_option('wp-short-pixel-quota-exceeded','0');
            ?><script>var shortPixelQuotaExceeded = 0;</script><?php
        }
        else {    
            $this->view->displayQuotaExceededAlert($quotaData);
            ?><script>var shortPixelQuotaExceeded = 1;</script><?php
        }
        return $quotaData;
    }

    public function bulkProcess() {
        global $wpdb;

        if( $this->_verifiedKey == false ) {//invalid API Key
            ShortPixelView::displayApiKeyAlert();
            return;
        }
        
        $quotaData = $this->checkQuotaAndAlert();
        if(self::getOpt('wp-short-pixel-quota-exceeded', 0) != 0) return;
        
        if(isset($_POST['bulkProcessPause'])) 
        {//pause an ongoing bulk processing, it might be needed sometimes
            $this->prioQ->pauseBulk();
        }

        if(isset($_POST["bulkProcess"])) 
        {
            //set the thumbnails option 
            if ( isset($_POST['thumbnails']) ) {
                update_option('wp-short-process_thumbnails', 1);
            } else {
                update_option('wp-short-process_thumbnails', 0);
            }
            $this->prioQ->startBulk();
            self::log("BULK:  Start:  " . $this->prioQ->getStartBulkId() . ", stop: " . $this->prioQ->getStopBulkId() . " PrioQ: "
                 .json_encode($this->prioQ->get()));
        }//end bulk process  was clicked    
        
        if(isset($_POST["bulkProcessResume"])) 
        {
            $this->prioQ->resumeBulk();
        }//resume was clicked

        //figure out all the files that could be processed
        $qry = "SELECT count(*) FilesToBeProcessed FROM " . $wpdb->prefix . "postmeta
        WHERE meta_key = '_wp_attached_file' ";
        $allFiles = $wpdb->get_results($qry);
        //figure out the files that are left to be processed
        $qry_left = "SELECT count(*) FilesLeftToBeProcessed FROM " . $wpdb->prefix . "postmeta
        WHERE meta_key = '_wp_attached_file' AND post_id <= " . $this->prioQ->getStartBulkId();
        $filesLeft = $wpdb->get_results($qry_left);

        if ( $filesLeft[0]->FilesLeftToBeProcessed > 0 && $this->prioQ->bulkRunning() )//bulk processing was started and is still running
        {
            $msg = $this->bulkProgressMessage($this->prioQ->getDeltaBulkPercent(), $this->prioQ->getTimeRemaining());
            $this->view->displayBulkProcessingRunning($this->prioQ->getBulkPercent(), $msg);

//            $imagesLeft = $filesLeft[0]->FilesLeftToBeProcessed;
//            $totalImages = $allFiles[0]->FilesToBeProcessed;
//            echo "<p>{$imagesLeft} out of {$totalImages} images left to process.</p>";
//            echo ' <a class="button button-secondary" href="' . get_admin_url() .  'upload.php">Media Library</a> ';
        } else 
        {
            if($this->prioQ->bulkRan() && !$this->prioQ->bulkPaused()) {
                $this->prioQ->markBulkComplete();
            }
            
            //image count 
            //$imageCount = $this->countAllProcessableFiles();
            //$imgProcessedCount = $this->countAllProcessedFiles();
            $imageOnlyThumbs = $quotaData['totalFiles'] - $quotaData['mainFiles'];
            $thumbsProcessedCount = self::getOpt( 'wp-short-pixel-thumbnail-count', 0);//amount of optimized thumbnails
            $under5PercentCount =  self::getOpt( 'wp-short-pixel-files-under-5-percent', 0);//amount of under 5% optimized imgs.

            //average compression
            $averageCompression = self::getAverageCompression();
            $this->view->displayBulkProcessingForm($quotaData, $thumbsProcessedCount, $under5PercentCount,
                    $this->prioQ->bulkRan(), $averageCompression, get_option('wp-short-pixel-fileCount'), 
                    self::formatBytes(get_option('wp-short-pixel-savedSpace')), $this->prioQ->bulkPaused() ? $this->prioQ->getBulkPercent() : false);
        }
    }
    //end bulk processing
    
    public function bulkProgressMessage($percent, $minutes) {
        $timeEst = "";
        self::log("bulkProgressMessage(): percent: " . $percent);
        if($percent < 1 || $minutes == 0) {
            $timeEst = "";
        } elseif( $minutes > 2880) {
            $timeEst = "~ " . round($minutes / 1440) . " days left";
        } elseif ($minutes > 240) {
            $timeEst = "~ " . round($minutes / 60) . " hours left";
        } elseif ($minutes > 60) {
            $timeEst = "~ " . round($minutes / 60) . " hours " . round($minutes%60/10) * 10 . " min. left";
        } elseif ($minutes > 20) {
            $timeEst = "~ " . round($minutes / 10) * 10 . " minutes left";
        } else {
            $timeEst = "~ " . $minutes . " minutes left";
        }
        return $timeEst;
    }
    
    public function emptyBackup(){
            if(file_exists(SP_BACKUP_FOLDER)) {
                
                //extract all images from DB in an array. of course
                $attachments = null;
                $attachments = get_posts( array(
                    'numberposts' => -1,
                    'post_type' => 'attachment',
                    'post_mime_type' => 'image'
                ));
                
            
                //parse all images and set the right flag that the image has no backup
                foreach($attachments as $attachment) 
                {
                    if(self::isProcessable(get_attached_file($attachment->ID)) == false) continue;
                    
                    $meta = wp_get_attachment_metadata($attachment->ID);
                    $meta['ShortPixel']['NoBackup'] = true;
                    wp_update_attachment_metadata($attachment->ID, $meta);
                }

                //delete the actual files on disk
                $this->deleteDir(SP_BACKUP_FOLDER);//call a recursive function to empty files and sub-dirs in backup dir
            }
    }
    
    public function renderSettingsMenu() {
        if ( !current_user_can( 'manage_options' ) )  { 
            wp_die('You do not have sufficient permissions to access this page.');
        }

        //die(var_dump($_POST));
        $noticeHTML = "";
        $notice = null;
        
        //by default we try to fetch the API Key from wp-config.php (if defined)
        if ( !isset($_POST['save']) && !get_option('wp-short-pixel-verifiedKey') && defined("SHORTPIXEL_API_KEY") && strlen(SHORTPIXEL_API_KEY) == 20 )
        {
            $_POST['validate'] = "validate";
            $_POST['key'] = SHORTPIXEL_API_KEY;        
        }
        
        if(isset($_POST['save']) || (isset($_POST['validate']) && $_POST['validate'] == "validate")) {
            //handle API Key - common for save and validate
            $_POST['key'] = trim(str_replace("*","",$_POST['key']));
            
            if ( strlen($_POST['key']) <> 20 )
            {
                $KeyLength = strlen($_POST['key']);
    
                $notice = array("status" => "error", "msg" => "The key you provided has " .  $KeyLength . " characters. The API key should have 20 characters, letters and numbers only.<BR> <b>Please check that the API key is the same as the one you received in your confirmation email.</b><BR>
                If this problem persists, please contact us at <a href='mailto:help@shortpixel.com?Subject=API Key issues' target='_top'>help@shortpixel.com</a> or <a href='https://shortpixel.com/contact' target='_blank'>here</a>.");
            }
            else
            {
                $validityData = $this->getQuotaInformation($_POST['key'], true, isset($_POST['validate']) && $_POST['validate'] == "validate");
    
                $this->_apiKey = $_POST['key'];
                $this->_apiInterface->setApiKey($this->_apiKey);
                update_option('wp-short-pixel-apiKey', $_POST['key']);
                if($validityData['APIKeyValid']) {
                    if(isset($_POST['validate']) && $_POST['validate'] == "validate") {
                        // delete last status if it was no valid key
                        $lastStatus = get_option( 'wp-short-pixel-bulk-last-status');
                        if(isset($lastStatus) && $lastStatus['Status'] == ShortPixelAPI::STATUS_NO_KEY) {
                            delete_option( 'wp-short-pixel-bulk-last-status');
                        }
                        //display notification
                        $urlParts = explode("/", get_site_url());
                        if( $validityData['DomainCheck'] == 'NOT Accessible'){
                            $notice = array("status" => "warn", "msg" => "API Key is valid but your site is not accessible from our servers. 
                                   Please make sure that your server is accessible from the Internet before using the API or otherwise we won't be able to optimize them.");
                        } else {
                            if ( function_exists("is_multisite") && is_multisite() )
                                $notice = array("status" => "success", "msg" => "API Key valid! <br>You seem to be running a multisite, please note that API Key can also be configured in wp-config.php like this:<BR> <b>define('SHORTPIXEL_API_KEY', '".$this->_apiKey."');</b>");
                            else
                                $notice = array("status" => "success", "msg" => 'API Key valid!');
                        }
                    }
                    update_option('wp-short-pixel-verifiedKey', true);
                    $this->_verifiedKey = true;
                    //test that the "uploads"  have the right rights and also we can create the backup dir for ShortPixel
                    if ( !file_exists(SP_BACKUP_FOLDER) && !@mkdir(SP_BACKUP_FOLDER, 0777, true) )
                        $notice = array("status" => "error", "msg" => "There is something preventing us to create a new folder for backing up your original files.<BR>
                        Please make sure that folder <b>" . WP_CONTENT_DIR . DIRECTORY_SEPARATOR . "uploads</b> has the necessary write and read rights.");
                } else {
                    if(isset($_POST['validate'])) {
                        //display notification
                        $notice = array("status" => "error", "msg" => $validityData["Message"]);
                    }
                    update_option('wp-short-pixel-verifiedKey', false);
                    $this->_verifiedKey = false;
                }
            }


            //if save button - we process the rest of the form elements
            if(isset($_POST['save'])) {
                update_option('wp-short-pixel-compression', $_POST['compressionType']);
                $this->_compressionType = $_POST['compressionType'];
                $this->_apiInterface->setCompressionType($this->_compressionType);
                if(isset($_POST['thumbnails'])) { $this->_processThumbnails = 1; } else { $this->_processThumbnails = 0; }
                if(isset($_POST['backupImages'])) { $this->_backupImages = 1; } else { $this->_backupImages = 0; }
                if(isset($_POST['cmyk2rgb'])) { $this->_CMYKtoRGBconversion = 1; } else { $this->_CMYKtoRGBconversion = 0; }
                update_option('wp-short-process_thumbnails', $this->_processThumbnails);
                update_option('wp-short-backup_images', $this->_backupImages);
                update_option('wp-short-pixel_cmyk2rgb', $this->_CMYKtoRGBconversion);
                $this->_resizeImages = (isset($_POST['resize']) ? 1: 0);
                $this->_resizeWidth = (isset($_POST['width']) ? $_POST['width']: $this->_resizeWidth);
                $this->_resizeHeight = (isset($_POST['height']) ? $_POST['height']: $this->_resizeHeight);
                update_option( 'wp-short-pixel-resize-images', $this->_resizeImages);   
                update_option( 'wp-short-pixel-resize-width', 0 + $this->_resizeWidth);        
                update_option( 'wp-short-pixel-resize-height', 0 + $this->_resizeHeight);                
                
                if($_POST['save'] == "Bulk Process") {
                    wp_redirect("upload.php?page=wp-short-pixel-bulk");
                    exit();
                }
            }
        }
        //now output headers. They were prevented with noheaders=true in the form url in order to be able to redirect if bulk was pressed
        if(isset($_REQUEST['noheader'])) {
            require_once(ABSPATH . 'wp-admin/admin-header.php');
        }
        
        //empty backup
        if(isset($_POST['emptyBackup'])) {
            $this->emptyBackup();
        }

        $quotaData = $this->checkQuotaAndAlert();

        if($this->_verifiedKey) {
            $fileCount = number_format(get_option('wp-short-pixel-fileCount'));
            $savedSpace = self::formatBytes(get_option('wp-short-pixel-savedSpace'),2);
            $averageCompression = self::getAverageCompression();
            $savedBandwidth = self::formatBytes(get_option('wp-short-pixel-savedSpace') * 10000,2);
            if (is_numeric($quotaData['APICallsQuota'])) {
                $quotaData['APICallsQuota'] .= "/month";
            }
            $backupFolderSize = self::formatBytes(self::folderSize(SP_BACKUP_FOLDER));
            $remainingImages = $quotaData['APICallsQuotaNumeric'] + $quotaData['APICallsQuotaOneTimeNumeric'] - $quotaData['APICallsMadeNumeric'] - $quotaData['APICallsMadeOneTimeNumeric'];
            $remainingImages = ( $remainingImages < 0 ) ? 0 : number_format($remainingImages);
            $totalCallsMade = number_format($quotaData['APICallsMadeNumeric'] + $quotaData['APICallsMadeOneTimeNumeric']);
            
            $resources = wp_remote_get("https://shortpixel.com/resources-frag");
            $this->view->displaySettings($quotaData, $notice, $resources, $averageCompression, $savedSpace, $savedBandwidth, 
                                         $remainingImages, $totalCallsMade, $fileCount, $backupFolderSize);        
        } else {
            $this->view->displaySettings($quotaData, $notice);        
        }
        
    }

    public function getAverageCompression(){
        return get_option('wp-short-pixel-total-optimized') > 0 
               ? round(( 1 -  ( get_option('wp-short-pixel-total-optimized') / get_option('wp-short-pixel-total-original') ) ) * 100, 2) 
               : 0;
    }
    
    /**
     * 
     * @param type $apiKey
     * @param type $appendUserAgent
     * @param type $validate - true if we are validating the api key, send also the domain name and number of pics
     * @return type
     */
    public function getQuotaInformation($apiKey = null, $appendUserAgent = false, $validate = false) {
    
        if(is_null($apiKey)) { $apiKey = $this->_apiKey; }

        $requestURL = 'https://api.shortpixel.com/v2/api-status.php';
        $args = array('timeout'=> SP_VALIDATE_MAX_TIMEOUT,
            'sslverify'   => false,
            'body' => array('key' => $apiKey)
        );
        $argsStr = "?key=".$apiKey;

        if($appendUserAgent) {
            $args['body']['useragent'] = "Agent" . urlencode($_SERVER['HTTP_USER_AGENT']);
            $argsStr .= "&useragent=Agent".$args['body']['useragent'];
        }
        if($validate) {
            $args['body']['DomainCheck'] = get_site_url();
            $imageCount = $this->countAllProcessableFiles();
            $args['body']['ImagesCount'] = $imageCount['mainFiles'];
            $args['body']['ThumbsCount'] = $imageCount['totalFiles'] - $imageCount['mainFiles'];
            $argsStr .= "&DomainCheck={$args['body']['DomainCheck']}&ImagesCount={$imageCount['mainFiles']}&ThumbsCount={$args['body']['ThumbsCount']}";
        }

        //Try first HTTPS post
        $response = wp_remote_post($requestURL, $args);
        //some hosting providers won't allow https:// POST connections so we try http:// as well
        if(is_wp_error( $response )) 
            $response = wp_remote_post(str_replace('https://', 'http://', $requestURL), $args);    
        //Second fallback to HTTP get
        if(is_wp_error( $response )){
            $args['body'] = null;
            $response = wp_remote_get(str_replace('https://', 'http://', $requestURL).$argsStr, $args);
        }
        $defaultData = array(
            "APIKeyValid" => false,
            "Message" => 'API Key could not be validated due to a connectivity error.<BR>Your firewall may be blocking us. Please contact your hosting provider and ask them to allow connections from your site to IP 176.9.106.46.<BR> If you still cannot validate your API Key after this, please <a href="https://shortpixel.com/contact" target="_blank">contact us</a> and we will try to help. ',
            "APICallsMade" => 'Information unavailable. Please check your API key.',
            "APICallsQuota" => 'Information unavailable. Please check your API key.',
            "DomainCheck" => 'NOT Accessible');

        if(is_object($response) && get_class($response) == 'WP_Error') {
            
            $urlElements = parse_url($requestURL);
            $portConnect = @fsockopen($urlElements['host'],8,$errno,$errstr,15);
            if(!$portConnect)
                $defaultData['Message'] .= "<BR>Debug info: <i>$errstr</i>";
    
            return $defaultData;
        }

        if($response['response']['code'] != 200) {
            return $defaultData;
        }

        $data = $response['body'];
        $data = $this->parseJSON($data);

        if(empty($data)) { return $defaultData; }

        if($data->Status->Code != 2) {
            $defaultData['Message'] = $data->Status->Message;
            return $defaultData;
        }

        if ( ( $data->APICallsMade + $data->APICallsMadeOneTime ) < ( $data->APICallsQuota + $data->APICallsQuotaOneTime ) ) //reset quota exceeded flag -> user is allowed to process more images. 
            update_option('wp-short-pixel-quota-exceeded',0);
        else
            update_option('wp-short-pixel-quota-exceeded',1);//activate quota limiting            

        //if a not valid status exists, delete it
        $lastStatus = self::getOpt( 'wp-short-pixel-bulk-last-status', array('Status' => ShortPixelAPI::STATUS_SUCCESS));
        if($lastStatus['Status'] == ShortPixelAPI::STATUS_NO_KEY) {
            delete_option('wp-short-pixel-bulk-last-status');
        }
            
        return array(
            "APIKeyValid" => true,
            "APICallsMade" => number_format($data->APICallsMade) . ' images',
            "APICallsQuota" => number_format($data->APICallsQuota) . ' images',
            "APICallsMadeOneTime" => number_format($data->APICallsMadeOneTime) . ' images',
            "APICallsQuotaOneTime" => number_format($data->APICallsQuotaOneTime) . ' images',
            "APICallsMadeNumeric" => $data->APICallsMade,
            "APICallsQuotaNumeric" => $data->APICallsQuota,
            "APICallsMadeOneTimeNumeric" => $data->APICallsMadeOneTime,
            "APICallsQuotaOneTimeNumeric" => $data->APICallsQuotaOneTime,
            "APILastRenewalDate" => $data->DateSubscription,
            "DomainCheck" => (isset($data->DomainCheck) ? $data->DomainCheck : null)
        );
    }

    public function generateCustomColumn( $column_name, $id ) {
        if( 'wp-shortPixel' == $column_name ) {
            $data = wp_get_attachment_metadata($id);
            $file = get_attached_file($id);
            $fileExtension = strtolower(substr($file,strrpos($file,".")+1));

            print "<div id='sp-msg-{$id}'>";
            
            if ( empty($data) )
            {
                if ( $fileExtension <> "pdf" )    
                {
                    if(!$this->_verifiedKey)
                        print 'Invalid API Key. <a href="options-general.php?page=wp-shortpixel">Check your Settings</a>';
                    else
                        print 'Optimization N/A';
                }
                else
                {
                    if ( get_option('wp-short-pixel-quota-exceeded') )
                    {
                        print QUOTA_EXCEEDED;
                        return;
                    }
                    else
                    {
                        print 'PDF not processed';
                        //if($this->_verifiedKey) {
                            print " | <a href=\"javascript:manualOptimization({$id})\">Optimize now</a>";
                        //}
                        return;
                    }
                }
            }
            elseif ( isset( $data['ShortPixelImprovement'] ) ) 
            {
                if(isset($meta['ShortPixel']['BulkProcessing'])) 
                {
                    if ( get_option('wp-short-pixel-quota-exceeded') )
                    {
                        print QUOTA_EXCEEDED;
                    }
                    else
                    {
                        print 'Waiting for bulk processing';
                        print " | <a href=\"javascript:manualOptimization({$id})\">Optimize now</a>";
                    }
                }
                elseif( is_numeric($data['ShortPixelImprovement'])  ) {
                    if ( $data['ShortPixelImprovement'] < 5 ) {
                            if($data['ShortPixelImprovement'] > 0 ) {
                                print $data['ShortPixelImprovement'] . '% optimized<br>';   
                            }
                            print "Bonus processing";
                        } else {                    
                            print 'Reduced by ';
                            print $data['ShortPixelImprovement'] . '%';
                        }
                    if ( get_option('wp-short-backup_images') && !isset($data['ShortPixel']['NoBackup'])) //display restore backup option only when backup is active
                        print " | <a href=\"admin.php?action=shortpixel_restore_backup&amp;attachment_ID={$id}\">Restore backup</a>";
                    if (isset($data['sizes']) && count($data['sizes'])) {
                        print "<br>+" . count($data['sizes']) . " thumbnails optimized";
                    }
                }
                elseif ( $data['ShortPixelImprovement'] <> "Optimization N/A" )
                {
                    if ( trim(strip_tags($data['ShortPixelImprovement'])) == "Quota exceeded" )
                    {
                        print QUOTA_EXCEEDED;
                        if ( !get_option('wp-short-pixel-quota-exceeded') )
                            print " | <a href=\"javascript:manualOptimization({$id})\">Try again</a>";
                    }
                    elseif ( trim(strip_tags($data['ShortPixelImprovement'])) == "Cannot write optimized file" )
                    {
                        print $data['ShortPixelImprovement'];
                        print " - <a href='https://shortpixel.com/faq#cannot-write-optimized-file' target='_blank'>Why?</a>";
                    } 
                    else
                    {
                        print $data['ShortPixelImprovement'];
                        print " | <a href=\"javascript:manualOptimization({$id})\">Try again</a>";
                    }
                }    
                else
                {
                    print "Optimization N/A";
                }
            } elseif(isset($data['ShortPixel']['WaitingProcessing'])) {
                if ( get_option('wp-short-pixel-quota-exceeded') )
                {
                    print QUOTA_EXCEEDED;
                }
                else
                {
                    print "<img src=\"" . plugins_url( 'img/loading.gif', __FILE__ ) . "\">&nbsp;Image waiting to be processed
                          | <a href=\"javascript:manualOptimization({$id})\">Retry</a></div>";
                    if($id > $this->prioQ->getFlagBulkId()) $this->prioQ->push($id); //should be there but just to make sure
                }    

            } elseif(isset($data['ShortPixel']['NoFileOnDisk'])) {
                print 'Image does not exist';

            } else {
                
                if ( wp_attachment_is_image( $id ) ) 
                {
                    if ( get_option('wp-short-pixel-quota-exceeded') )
                    {
                        print QUOTA_EXCEEDED;
                    }
                    else
                    {
                        print 'Image not processed';
                        print " | <a href=\"javascript:manualOptimization({$id})\">Optimize now</a>";
                    }
                    if (count($data['sizes'])) {
                        print "<br>+" . count($data['sizes']) . " thumbnails";
                    }
                }
                elseif ( $fileExtension == "pdf" )
                {
                    if ( get_option('wp-short-pixel-quota-exceeded') )
                    {
                        print QUOTA_EXCEEDED;
                    }
                    else
                    {
                        print 'PDF not processed';
                        print " | <a href=\"javascript:manualOptimization({$id})\">Optimize now</a>";
                    }
                }
            }
            print "</div>";
        }
    }

    public function columns( $defaults ) {
        $defaults['wp-shortPixel'] = 'ShortPixel Compression';
        return $defaults;
    }

    public function generatePluginLinks($links) {
        $in = '<a href="options-general.php?page=wp-shortpixel">Settings</a>';
        array_unshift($links, $in);
        return $links;
    }

    public function parseJSON($data) {
        if ( function_exists('json_decode') ) {
            $data = json_decode( $data );
        } else {
            require_once( 'JSON/JSON.php' );
            $json = new Services_JSON( );
            $data = $json->decode( $data );
        }
        return $data;
    }


    static public function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    static public function isProcessable($ID) {
        $path = get_attached_file($ID);//get the full file PATH
        $pathParts = pathinfo($path);
        if( isset($pathParts['extension']) && in_array(strtolower($pathParts['extension']), array('jpg', 'jpeg', 'gif', 'png', 'pdf'))) {
                return true;
            } else {
                return false;
            }
    }


    //return an array with URL(s) and PATH(s) for this file
    public function getURLsAndPATHs($ID, $meta = NULL) { 
        
        if ( !parse_url(WP_CONTENT_URL, PHP_URL_SCHEME) )
        {//no absolute URLs used -> we implement a hack
           $url = get_site_url() . wp_get_attachment_url($ID);//get the file URL 
        }
        else
            $url = wp_get_attachment_url($ID);//get the file URL
       
        $urlList[] = $url;
        $path = get_attached_file($ID);//get the full file PATH
        $filePath[] = $path;
        if ( $meta == NULL ) {
            $meta = wp_get_attachment_metadata($ID);
        }

        //it is NOT a PDF file and thumbs are processable
        if (    strtolower(substr($filePath[0],strrpos($filePath[0], ".")+1)) != "pdf" 
             && $this->_processThumbnails 
             && isset($meta['sizes']) && is_array($meta['sizes'])) 
        {
            foreach( $meta['sizes'] as $thumbnailInfo ) 
                {
                    $urlList[] = str_replace(ShortPixelAPI::MB_basename($urlList[0]), $thumbnailInfo['file'], $url);
                    $filePath[] = str_replace(ShortPixelAPI::MB_basename($filePath[0]), $thumbnailInfo['file'], $path);
                }            
        }
        if(!isset($meta['sizes']) || !is_array($meta['sizes'])) {
            self::log("getURLsAndPATHs: no meta sizes for ID $ID : " . json_encode($meta));
        }
        return array("URLs" => $urlList, "PATHs" => $filePath);
    }
    

    public static function deleteDir($dirPath) {
        if (substr($dirPath, strlen($dirPath) - 1, 1) !=
         '/') {
            $dirPath .= '/';
        }
        $files = glob($dirPath . '*', GLOB_MARK);
        foreach ($files as $file) {
            if (is_dir($file)) {
                self::deleteDir($file);
                @rmdir($file);//remove empty dir
            } else {
                @unlink($file);//remove file
            }
        }
    }

    static public function folderSize($path) {
        $total_size = 0;
        if(file_exists($path)) {
            $files = scandir($path);
        } else {
            return $total_size;
        }
        $cleanPath = rtrim($path, '/'). '/';
        foreach($files as $t) {
            if ($t<>"." && $t<>"..") 
            {
                $currentFile = $cleanPath . $t;
                if (is_dir($currentFile)) {
                    $size = self::folderSize($currentFile);
                    $total_size += $size;
                }
                else {
                    $size = filesize($currentFile);
                    $total_size += $size;
                }
            }
        }
        return $total_size;
    }
    
    public static function getMaxMediaId() {
        global  $wpdb;
        $queryMax = "SELECT max(post_id) as QueryID FROM " . $wpdb->prefix . "postmeta";
        $resultQuery = $wpdb->get_results($queryMax);
        return $resultQuery[0]->QueryID;
    }
    
    public function getMinMediaId() {
        global  $wpdb;
        $queryMax = "SELECT min(post_id) as QueryID FROM " . $wpdb->prefix . "postmeta";
        $resultQuery = $wpdb->get_results($queryMax);
        return $resultQuery[0]->QueryID;
    }

    //count all the processable files in media library (while limiting the results to max 10000)
    public function countAllProcessableFiles($maxId = PHP_INT_MAX, $minId = 0){
        global  $wpdb;
        
        $totalFiles = 0;
        $mainFiles = 0;
        $limit = 500;
        $pointer = 0;

        //count all the files, main and thumbs 
        while ( 1 ) 
        {
            $filesList= $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "postmeta
                                        WHERE ( post_id <= $maxId AND post_id > $minId ) 
                                          AND ( meta_key = '_wp_attached_file' OR meta_key = '_wp_attachment_metadata' ) 
                                        LIMIT $pointer,$limit");
            if ( empty($filesList) ) //we parsed all the results
                break;
             
            foreach ( $filesList as $file ) 
            {
                if ( $file->meta_key == "_wp_attached_file" )
                {//count pdf files only
                    $extension = substr($file->meta_value, strrpos($file->meta_value,".") + 1 );
                    if ( $extension == "pdf" )
                    {
                        $totalFiles++;
                        $mainFiles++;
                    }
                }
                else
                {
                    $attachment = unserialize($file->meta_value);
                    if ( isset($attachment['sizes']) )
                        $totalFiles += count($attachment['sizes']);            
    
                    if ( isset($attachment['file']) )
                    {
                        $totalFiles++;
                        $mainFiles++;
                    }
                }
            }   
            unset($filesList);
            $pointer += $limit;
            
        }//end while
 
        return array("totalFiles" => $totalFiles, "mainFiles" => $mainFiles);
}  


    //count all the processable files in media library (while limiting the results to max 10000)
    public function countAllProcessedFiles($maxId = PHP_INT_MAX, $minId = 0){
        global  $wpdb;
        
        $processedMainFiles = $processedTotalFiles = 0;
        $limit = 500;
        $pointer = 0;

        //count all the files, main and thumbs 
        while ( 1 ) 
        {
            $filesList= $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "postmeta
                                        WHERE ( post_id <= $maxId AND post_id > $minId ) 
                                          AND ( meta_key = '_wp_attachment_metadata' ) 
                                        LIMIT $pointer,$limit");
            if ( empty($filesList) ) {//we parsed all the results
                break;
            }
            foreach ( $filesList as $file ) 
            {
                $attachment = unserialize($file->meta_value);
                if ( isset($attachment['ShortPixelImprovement']) && ($attachment['ShortPixelImprovement'] > 0 || $attachment['ShortPixelImprovement'] === 0.0)) {
                    $processedMainFiles++;            
                    $processedTotalFiles++;            
                    if ( isset($attachment['sizes']) ) {
                        $processedTotalFiles += count($attachment['sizes']);            
                    }
                }
            }   
            unset($filesList);
            $pointer += $limit;
            
        }//end while
 
        return array("totalFiles" => $processedTotalFiles, "mainFiles" => $processedMainFiles);
    }  

    public function migrateBackupFolder() {
        $oldBackupFolder = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'ShortpixelBackups';

        if(!file_exists($oldBackupFolder)) return;  //if old backup folder does not exist then there is nothing to do

        if(!file_exists(SP_BACKUP_FOLDER)) {
            //we check that the backup folder exists, if not we create it so we can copy into it
            if(!mkdir(SP_BACKUP_FOLDER, 0777, true)) return;
        }

        $scannedDirectory = array_diff(scandir($oldBackupFolder), array('..', '.'));
        foreach($scannedDirectory as $file) {
            @rename($oldBackupFolder.DIRECTORY_SEPARATOR.$file, SP_BACKUP_FOLDER.DIRECTORY_SEPARATOR.$file);
        }
        $scannedDirectory = array_diff(scandir($oldBackupFolder), array('..', '.'));
        if(empty($scannedDirectory)) {
            @rmdir($oldBackupFolder);
        }

        return;
    }
    
    function getMaxIntermediateImageSize() {
        global $_wp_additional_image_sizes;

        $width = 0;
        $height = 0;
        $get_intermediate_image_sizes = get_intermediate_image_sizes();

        // Create the full array with sizes and crop info
        foreach( $get_intermediate_image_sizes as $_size ) {
            if ( in_array( $_size, array( 'thumbnail', 'medium', 'large' ) ) ) {
                $width = max($width, get_option( $_size . '_size_w' ));
                $height = max($height, get_option( $_size . '_size_h' ));
                //$sizes[ $_size ]['crop'] = (bool) get_option( $_size . '_crop' );
            } elseif ( isset( $_wp_additional_image_sizes[ $_size ] ) ) {
                $width = max($width, $_wp_additional_image_sizes[ $_size ]['width']);
                $height = max($height, $_wp_additional_image_sizes[ $_size ]['height']);
                //'crop' =>  $_wp_additional_image_sizes[ $_size ]['crop']
            }
        }
        return array('width' => $width, 'height' => $height);
    }

    public function getApiKey() {
        return $this->_apiKey;
    }
    
    public function getPrioQ() {
        return $this->prioQ;
    }
    
    public function backupImages() {
        return $this->_backupImages;
    }

    public function processThumbnails() {
        return $this->_processThumbnails;
    }
    public function getCMYKtoRGBconversion() {
        return $this->_CMYKtoRGBconversion;
    }

        public function getResizeImages() {
        return $this->_resizeImages;
    }

    public function getResizeWidth() {
        return $this->_resizeWidth;
    }

    public function getResizeHeight() {
        return $this->_resizeHeight;
    }
    public function getAffiliateSufix() {
        return $this->_affiliateSufix;
    }
    public function getVerifiedKey() {
        return $this->_verifiedKey;
    }
    public function getCompressionType() {
        return $this->_compressionType;
    }

}

function onInit() {
    if ( ! is_admin() || !is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $pluginInstance = new WPShortPixel;
    global $pluginInstance;
} 

if ( !function_exists( 'vc_action' ) || vc_action() !== 'vc_inline' ) { //handle incompatibility with Visual Composer
    add_action( 'init',  'onInit');

    register_activation_hook( __FILE__, array( 'WPShortPixel', 'shortPixelActivatePlugin' ) );
    register_deactivation_hook( __FILE__, array( 'WPShortPixel', 'shortPixelDeactivatePlugin' ) );

}

//$pluginInstance = new WPShortPixel();
//global $pluginInstance;

?>

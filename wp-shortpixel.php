<?php
/**
 * Plugin Name: ShortPixel Image Optimiser
 * Plugin URI: https://shortpixel.com/
 * Description: ShortPixel is an image compression tool that helps improve your website performance. The plugin optimises images automatically using both lossy and lossless compression. Resulting, smaller, images are no different in quality from the original. To install: 1) Click the "Activate" link to the left of this description. 2) <a href="https://shortpixel.com/wp-apikey" target="_blank">Free Sign up</a> for your unique API Key . 3) Check your email for your API key. 4) Use your API key to activate ShortPixel plugin in the 'Plugins' menu in WordPress. 5) Done!
 * Version: 1.4.1
 * Author: ShortPixel
 * Author URI: https://shortpixel.com
 */

require_once('shortpixel_api.php');
require_once( ABSPATH . 'wp-admin/includes/image.php' );

define('SP_DEBUG', false);
define('SP_LOG', false);
define('SP_MAX_TIMEOUT', 10);
define('SP_BACKUP_FOLDER', WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'ShortpixelBackups');
define('MUST_HAVE_KEY', true);
define('SP_DEBUG', true);
define('BATCH_SIZE', 1);

class WPShortPixel {

    private $_apiInterface = null;
    private $_apiKey = '';
    private $_compressionType = '';
    private $_processThumbnails = 1;
    private $_backupImages = 1;
        private $_verifiedKey = false;

    public function __construct() {
        $this->populateOptions();

        $this->_apiInterface = new shortpixel_api($this->_apiKey, $this->_compressionType);

        //add hook for image upload processing
        add_filter( 'wp_generate_attachment_metadata', array( &$this, 'handleImageUpload' ), 10, 2 );
        add_filter( 'manage_media_columns', array( &$this, 'columns' ) );
        add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array(&$this, 'generatePluginLinks'));

        //add_action( 'admin_footer', array(&$this, 'handleImageProcessing'));
        add_action( 'manage_media_custom_column', array( &$this, 'generateCustomColumn' ), 10, 2 );

        //add settings page
        add_action( 'admin_menu', array( &$this, 'registerSettingsPage' ) );
        add_action( 'admin_menu', array( &$this, 'registerAdminPage' ) );
        add_action( 'admin_notices', array( &$this, 'displayNotice' ) );
        add_action( 'delete_attachment', array( &$this, 'handleDeleteAttachmentInBackup' ) );

        //automatic optimization
        add_action( 'admin_footer', array( &$this, 'my_action_javascript') );
        add_action( 'wp_ajax_my_action', array( &$this, 'handleImageProcessing') );

        //manual optimization
        add_action('admin_action_shortpixel_manual_optimize', array(&$this, 'handleManualOptimization'));
        //backup restore
        add_action('admin_action_shortpixel_restore_backup', array(&$this, 'handleRestoreBackup'));

        //bulk processing in media library
        //add_action('load-upload.php', array(&$this, 'wp_load_admin_js'));
        //add_action('admin_enqueue_scripts', array(&$this, 'bulkOptimizeActionHandler'));
    }

    public function populateOptions() {

        if(get_option('wp-short-pixel-apiKey') != false) {
            $this->_apiKey = get_option('wp-short-pixel-apiKey');
        } else {
            add_option( 'wp-short-pixel-apiKey', '', '', 'yes' );
        }

        if(get_option('wp-short-pixel-verifiedKey') != false) {
            $this->_verifiedKey = get_option('wp-short-pixel-verifiedKey');
        }

        if(get_option('wp-short-pixel-compression') != false) {
            $this->_compressionType = get_option('wp-short-pixel-compression');
        }

        if(get_option('wp-short-process_thumbnails') != false) {
            $this->_processThumbnails = get_option('wp-short-process_thumbnails');
        } else {
            add_option('wp-short-process_thumbnails', $this->_processThumbnails, '', 'yes' );
        }

        if(get_option('wp-short-backup_images') != false) {
            $this->_backupImages = get_option('wp-short-backup_images');
        } else {
            add_option('wp-short-backup_images', $this->_backupImages, '', 'yes' );
        }

        if(get_option('wp-short-pixel-fileCount') === false) {
            add_option( 'wp-short-pixel-fileCount', 0, '', 'yes' );
        }

        if(get_option('wp-short-pixel-savedSpace') === false) {
            add_option( 'wp-short-pixel-savedSpace', 0, '', 'yes' );
        }

        if(get_option('wp-short-pixel-averageCompression') === false) {
            add_option( 'wp-short-pixel-averageCompression', 0, '', 'yes' );
        }
    }

    static function log($message) {
        if(SP_DEBUG) {
            echo "{$message}</br>";
        }
    }

    function my_action_javascript() { ?>
        <script type="text/javascript" >
            jQuery(document).ready(sendRequest());
            function sendRequest() {
                var data = { 'action': 'my_action' };
                // since 2.8 ajaxurl is always defined in the admin header and points to admin-ajax.php
                jQuery.post(ajaxurl, data, function(response) {
                    if(response == 'empty queue') {
                        console.log('Queue is empty');
                    } else {
                        console.log('Server response: ' + response);
                        sendRequest();
                    }
                });
            }
        </script> <?php
    }

    function wp_load_admin_js() {
        add_action('admin_print_footer_scripts', array(&$this, 'add_bulk_actions_via_javascript'));
    }

    function add_bulk_actions_via_javascript() {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($){
                $('select[name^="action"] option:last-child').before('<option value="2">Bulk Optimize</option>');
            });
        </script>
    <?php }

    //handling older
    public function WPShortPixel() {
        $this->__construct();
    }

    public function handleImageUpload($meta, $ID = null) {
        if(MUST_HAVE_KEY && $this->_verifiedKey) {
            self::log("Processing image id {$ID}");
            $url = wp_get_attachment_url($ID);
            $path = get_attached_file($ID);
            $this->_apiInterface->doRequests($url, $path, $ID);
        } else {

        }
        $meta['ShortPixel']['WaitingProcessing'] = true;
        return $meta;
    }

    public function handleImageProcessing($ID = null) {
        if(MUST_HAVE_KEY && $this->_verifiedKey == false) {
            echo "Missing API Key";
            die();
        }

        //query database for first found entry that needs processing
        global  $wpdb;
        $qry = "SELECT post_id FROM " . $wpdb->prefix . "postmeta WHERE meta_value LIKE '%\"WaitingProcessing\";b:1;%' LIMIT " . BATCH_SIZE;
        $idList = $wpdb->get_results($qry);

        if(empty($idList)) { echo 'empty queue'; die; }

        foreach($idList as $post) {
            $ID = $post->post_id;
            $imageURL =  wp_get_attachment_url($ID);
            $imagePath = get_attached_file($ID);
            $meta = wp_get_attachment_metadata($ID);

            $result = $this->_apiInterface->processImage($imageURL, $imagePath, $ID);

            if(is_string($result)) {
                $meta['ShortPixelImprovement'] = $result;
                die;
            }

            $processThumbnails = get_option('wp-short-process_thumbnails');

            //handle the rest of the thumbnails generated by WP
            if($processThumbnails && $result && !empty($meta['sizes'])) {
                foreach($meta['sizes'] as $thumbnailInfo) {
                    $thumbURL = str_replace(basename($imagePath), $thumbnailInfo['file'], $imageURL);
                    $thumbPath = str_replace(basename($imagePath), $thumbnailInfo['file'], $imagePath);
                    $this->_apiInterface->processImage($thumbURL, $thumbPath);
                }
            }

            unset($meta['ShortPixel']['WaitingProcessing']);
            if(isset($meta['ShortPixel']['BulkProcessing'])) {
                unset($meta['ShortPixel']['BulkProcessing']);
            }

            //check bulk processing
            $bulkLog = get_option('bulkProcessingLog');
            if(isset($bulkLog['toDo'])) {
                if(array_key_exists($ID, $bulkLog['toDo'])) {
                    unset($bulkLog['toDo'][$ID]);
                }
            }

            if(empty($bulkLog['toDo'])) { delete_option('bulkProcessingLog'); }
            else { update_option('bulkProcessingLog', $bulkLog); }

            $meta['ShortPixelImprovement'] = $result->PercentImprovement;

            wp_update_attachment_metadata($ID, $meta);
            echo "Processing done succesfully for image #{$ID}";
        }

        die();
    }

    public function handleManualOptimization() {
        $attachmentID = intval($_GET['attachment_ID']);

        $url = wp_get_attachment_url($attachmentID);
        $filePath = get_attached_file($attachmentID);

        $this->_apiInterface->processImage($url, $filePath, $attachmentID);

        // store the referring webpage location
        $sendback = wp_get_referer();
        // sanitize the referring webpage location
        $sendback = preg_replace('|[^a-z0-9-~+_.?#=&;,/:]|i', '', $sendback);
        // send the user back where they came from
        wp_redirect($sendback);
        // we are done,
    }

    public function handleRestoreBackup() {
        $attachmentID = intval($_GET['attachment_ID']);

        $uploadFilePath = get_attached_file($attachmentID);
        $meta = wp_get_attachment_metadata($attachmentID);
        $pathInfo = pathinfo($uploadFilePath);

        try {
            //main file
            @rename(SP_BACKUP_FOLDER . DIRECTORY_SEPARATOR . basename($uploadFilePath), $uploadFilePath);
            //overwriting thumbnails
            if(is_array($meta["sizes"])) {
                foreach($meta["sizes"] as $size => $imageData) {
                    $source = SP_BACKUP_FOLDER . DIRECTORY_SEPARATOR . $imageData['file'];
                    $destination = $pathInfo['dirname'] . DIRECTORY_SEPARATOR . $imageData['file'];
                    @rename($source, $destination);
                }
            }

            unset($meta["ShortPixelImprovement"]);
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
        $uploadFilePath = get_attached_file($ID);
        $meta = wp_get_attachment_metadata($ID);

        try {
            //main file
            @unlink(SP_BACKUP_FOLDER . DIRECTORY_SEPARATOR . basename($uploadFilePath));
            //overwriting thumbnails
            foreach($meta["sizes"] as $size => $imageData) {
                @unlink(SP_BACKUP_FOLDER . DIRECTORY_SEPARATOR . $imageData['file']);
            }
        } catch(Exception $e) {
            //what to do, what to do?
        }

    }

    public function bulkOptimizeActionHandler($hook) {
        if($hook == 'upload.php') {
            if($_GET['action'] == 2) {
                if(!empty($_GET['media'])) {
                    $imageLog = array();
                    //remove all ShortPixel data from metadata
                    foreach($_GET['media'] as $attachmentID) {
                        $meta = wp_get_attachment_metadata($attachmentID);
                        $meta['ShortPixel']['WaitingProcessing'] = true;
                        $meta['ShortPixel']['BulkProcessing'] = true;
                        unset($meta['ShortPixelImprovement']);
                        wp_update_attachment_metadata($attachmentID, $meta);
                        $imageLog[$attachmentID] = false;
                    }
                    $bulkLog = array();
                    $bulkLog['running'] = true;
                    $bulkLog['toDo'] = $imageLog;
                    $bulkLog['total'] = count($imageLog);
                    update_option('bulkProcessingLog', $bulkLog);
                }
            }
        }
    }

    public function registerSettingsPage() {
        add_options_page( 'ShortPixel Settings', 'ShortPixel', 'manage_options', 'wp-shortpixel', array($this, 'renderSettingsMenu'));
    }

    function registerAdminPage( ) {
        add_media_page( 'ShortPixel Bulk Process', 'Bulk ShortPixel', 'edit_others_posts', 'wp-short-pixel-bulk', array( &$this, 'bulkProcesss' ) );
    }

    public function bulkProcesss() {
        echo '<h1>Bulk Image Optimisation by ShortPixel</h1>';

        echo '
            <script type="text/javascript" >
                jQuery(document).ready(function() {
                    if(bulkProcessingRunning) {
                        console.log("Bulk processing running");
                        setTimeout(function(){
                              window.location = window.location.href;
                            }, 30000);
                    } else {
                        console.log("No bulk processing is currently running");
                    }
                });
            </script>
        ';

        if(MUST_HAVE_KEY && $this->_verifiedKey == false) {
            echo "<p>In order to start processing your images, you need to validate your API key in the ShortPixel Settings. If you don’t have an API Key, you can get one delivered to your inbox.</p>";
            echo "<p>Don’t have an API Key yet? Get it now at <a href=\"https://shortpixel.com/wp-apikey\" target=\"_blank\">www.ShortPixel.com</a>, for free.</p>";
            return;
        }

        $attachments = null;
        $attachments = get_posts( array(
            'numberposts' => -1,
            'post_type' => 'attachment',
            'post_mime_type' => 'image'
        ));

        if($_POST["bulkProcess"]) {
            $imageLog = array();
            //remove all ShortPixel data from metadata
            foreach($attachments as $attachment) {
                $meta = wp_get_attachment_metadata($attachment->ID);
                $meta['ShortPixel']['WaitingProcessing'] = true;
                $meta['ShortPixel']['BulkProcessing'] = true;
                wp_update_attachment_metadata($attachment->ID, $meta);
                $imageLog[$attachment->ID] = false;
            }
            $bulkLog = array();
            $bulkLog['running'] = true;
            $bulkLog['toDo'] = $imageLog;
            $bulkLog['total'] = count($imageLog);
            update_option('bulkProcessingLog', $bulkLog);
        }

        $currentBulkProcessingStatus = get_option('bulkProcessingLog');

        if($currentBulkProcessingStatus && $currentBulkProcessingStatus['running']) {
            echo "<p>
					Bulk optimisation has started. It may take a while until we process all your images. The latest status of the processing will be displayed here every 30 seconds. 
					In the meantime, you can continue using the admin as usual. However, <b>you musn’t close the WordPress admin</b>, or the bulk processing will stop.
                  </p>";
            echo '
                <script type="text/javascript" >
                    var bulkProcessingRunning = true;
                 </script>
            ';

            $imagesLeft = count($currentBulkProcessingStatus["toDo"]);
            $totalImages = $currentBulkProcessingStatus['total'];

            echo "<p>{$imagesLeft} out of {$totalImages} images left to process.</p>";

            echo '
                <a class="button button-secondary" href="' . get_admin_url() .  'upload.php">Media Library</a>
            ';
        } else {
            echo $this->getBulkProcessingForm(count($attachments));
            echo '
                <script type="text/javascript" >
                    var bulkProcessingRunning = false;
                 </script>
            ';
        }
    }

    public function renderSettingsMenu() {
        if ( !current_user_can( 'manage_options' ) )  {
            wp_die('You do not have sufficient permissions to access this page.');
        }

        if(isset($_POST['submit']) || isset($_POST['validate'])) {
            //handle API Key - common for submit and validate
            $validityData = $this->getQuotaInformation($_POST['key']);
            $this->_apiKey = $_POST['key'];
            $this->_apiInterface->setApiKey($this->_apiKey);
            update_option('wp-short-pixel-apiKey', $_POST['key']);
            if($validityData['APIKeyValid']) {
                update_option('wp-short-pixel-verifiedKey', true);
                $this->_verifiedKey = true;
            } else {
                update_option('wp-short-pixel-verifiedKey', false);
                $this->_verifiedKey = false;
            }
            //if save button - we process the rest of the form elements
            if(isset($_POST['submit'])) {
                update_option('wp-short-pixel-compression', $_POST['compressionType']);
                $this->_compressionType = $_POST['compressionType'];
                $this->_apiInterface->setCompressionType($this->_compressionType);
                if(isset($_POST['thumbnails'])) { $this->_processThumbnails = 1; } else { $this->_processThumbnails = 0; }
                if(isset($_POST['backupImages'])) { $this->_backupImages = 1; } else { $this->_backupImages = 0; }
                update_option('wp-short-process_thumbnails', $this->_processThumbnails);
                update_option('wp-short-backup_images', $this->_backupImages);
            }
        }

        if(isset($_POST['emptyBackup'])) {
            if(file_exists(SP_BACKUP_FOLDER)) {
                $files = scandir(SP_BACKUP_FOLDER);
                $cleanPath = rtrim(SP_BACKUP_FOLDER, '/'). '/';
                foreach($files as $t) {
                    if ( $t != "." && $t != "..") {
                        unlink(SP_BACKUP_FOLDER . DIRECTORY_SEPARATOR . $t);
                    }
                }
            }
        }

        $checked = '';
        if($this->_processThumbnails) { $checked = 'checked'; }

        $checkedBackupImages = '';
        if($this->_backupImages) { $checkedBackupImages = 'checked'; }

        echo '<h1>ShortPixel Image Optimiser Settings</h1>';
        echo '<p>
                <a href="https://shortpixel.com">ShortPixel.com</a> |
                <a href="https://wordpress.org/plugins/shortpixel-image-optimiser/installation/">Installation </a> |
                <a href="https://wordpress.org/support/plugin/shortpixel-image-optimiser">Support </a>
              </p>';
        echo '<p>New images uploaded to the Media Library will be optimized automatically.<br/>If you have existing images you would like to optimize, you can use the <a href="' . get_admin_url()  . 'upload.php?page=wp-short-pixel-bulk">Bulk Optimisation Tool</a>.</p>';
        
        $formHTML = <<< HTML
<form name='wp_shortpixel_options' action=''  method='post' id='wp_shortpixel_options'>
<table class="form-table">
<tbody><tr>
<th scope="row"><label for="key">API Key:</label></th>
<td><input name="key" type="text" id="key" value="{$this->_apiKey}" class="regular-text">
    <input type="submit" name="validate" id="validate" class="button button-primary" title="Validate the provided API key" value="Validate">
</td>
</tr>
HTML;

        if(!$this->_verifiedKey) {
            //if invalid key we display the link to the API Key
            $formHTML .= '<tr><td style="padding-left: 0px;" colspan="2">Don’t have an API Key? <a href="https://shortpixel.com/wp-apikey" target="_blank">Sign up, it’s free.</a></td></tr>';
            $formHTML .= '</form>';
        } else {
            //if valid key we display the rest of the options
            $formHTML .= <<< HTML
<tr><th scope="row">
    <label for="compressionType">Compression type: <span title="
Lossy compression: lossy has a better compression rate than lossless compression. The resulting image is not 100% identical with the original. Works well for photos taken with your camera.
Lossless compression: the shrunk image will be identical with the original and smaller in size. Use this when you do not want to loose any of the original image's details. Works best for technical drawings, clip art and comics.
    ">?</span></label>
</th><td>
HTML;

            if($this->_compressionType == 'lossless') {
                $formHTML .= '<input type="radio" name="compressionType" value="lossy" >Lossy</br></br>';
                $formHTML .= '<input type="radio" name="compressionType" value="lossless" checked>Lossless';
            } else {
                $formHTML .= '<input type="radio" name="compressionType" value="lossy" checked>Lossy</br></br>';
                $formHTML .= '<input type="radio" name="compressionType" value="lossless" >Lossless';
            }

            $formHTML .= <<<HTML
</td>
</tr>
<tr>
<th scope="row"><label for="thumbnails">Compress also image thumbnails:</label></th>
<td><input name="thumbnails" type="checkbox" id="thumbnails" {$checked}></td>
</tr>
<tr>
<th scope="row"><label for="backupImages">Back up all images
<span title="If selected all images will be backed up in the ShortpixelBackups folder located in your wp-content folder">?</span>
</label></th>
<td>
<input name="backupImages" type="checkbox" id="backupImages" {$checkedBackupImages}>
</td>
</tr>
</tbody></table>
<p class="submit">
    <input type="submit" name="submit" id="submit" class="button button-primary" title="Save Changes" value="Save Changes">
    <a class="button button-primary" title="Process all the images in your Media Library" href="upload.php?page=wp-short-pixel-bulk">Bulk Process</a>
</p>
</form>
<script>
var rad = document.wp_shortpixel_options.compressionType;
var prev = null;
for(var i = 0; i < rad.length; i++) {
    rad[i].onclick = function() {

        if(this !== prev) {
            prev = this;
        }
        alert('Select Media/Bulk ShortPixel to reprocess all the images');
    };
}
</script>
HTML;
        }

        echo $formHTML;

        if($this->_verifiedKey) {
            $fileCount = get_option('wp-short-pixel-fileCount');
            $savedSpace = self::formatBytes(get_option('wp-short-pixel-savedSpace'),2);
            $averageCompression = round(get_option('wp-short-pixel-averageCompression'),2);
            $savedBandwidth = self::formatBytes(get_option('wp-short-pixel-savedSpace') * 1000,2);
            $quotaData = $this->getQuotaInformation();
            $backupFolderSize = self::formatBytes(self::folderSize(SP_BACKUP_FOLDER));

            $statHTML = <<< HTML
<h3>ShortPixlel Facts & Figures</h3>
<table class="form-table">
<tbody><tr>
<th scope="row"><label for="totalFiles">Your total number of processed files:</label></th>
<td>$fileCount</td>
</tr>
<tr>
<th scope="row"><label for="savedSpace">Saved space by ShortPixel</label></th>
<td>$savedSpace</td>
</tr>
<tr>
<th scope="row"><label for="savedBandwidth">Bandwith* saved with ShortPixel:</label></th>
<td>$savedBandwidth</td>
</tr>
<tr>
<th scope="row"><label for="apiQuota">Your ShortPixel plan</label></th>
<td>{$quotaData['APICallsQuota']}</td>
</tr>
<tr>
<th scope="row"><label for="usedQUota">Used Quota:</label></th>
<td>{$quotaData['APICallsMade']}</td>
</tr>
<tr>
<th scope="row"><label for="averagCompression">Average file size compression:</label></th>
<td>$averageCompression%</td>
</tr>
HTML;
            if($this->_backupImages) {
                $statHTML .= <<< HTML
<form action="" method="POST">
<tr>
<th scope="row"><label for="sizeBackup">Backup folder size:</label></th>
<td>
{$backupFolderSize}
<input type="submit"  style="margin-left: 15px; vertical-align: middle;" class="button button-secondary" name="emptyBackup" value="Empty backups"/>
</td>
</tr>
</form>
HTML;
            }

            $statHTML .= <<< HTML
</tbody></table>
<p>* Saved bandwidth is calculated at 100,000 impressions/image</p>
HTML;
            echo $statHTML;
        }
    }

    public function getBulkProcessingForm($imageCount) {
        return <<< HTML
</br>
Currently, you have {$imageCount} images in your library. </br>
</br>
<form action='' method="POST" >
<input type="submit" name="bulkProcess" id="bulkProcess" class="button button-primary" value="Compress all your images">
</form>
HTML;
    }

    public function displayNotice() {
        global $hook_suffix;

        $divHeader = '<br/><div class="updated">';
        $divWarningHeader = '<br/><div class="update-nag">';
        $divFooter = '</div>';

        $noticeInvalidKeyContent = '
        <div style="background-color: #FFFFFF; box-shadow: 0 1px 1px 0 rgba(0, 0, 0, 0.1); padding: 1px 12px; margin: 15px 0; ">
            <p>
                <a href="options-general.php?page=wp-shortpixel">Activate your ShortPixel plugin.</a>
                Get an API key <a href="https://shortpixel.com/wp-apikey" target="_blank">here</a>. Sign up, it’s free.
           </p>
        </div>
        ';
        $noticeWrongAPIKeyContent = '<p>API Key invalid!</p>';
        $noticeCorrectAPIKeyContent = '<p>API Key valid!</p>';

        if($hook_suffix == 'settings_page_wp-shortpixel' && !empty($_POST)) {
            $keyCheckData = $this->getQuotaInformation($_POST['key']);
            if($keyCheckData['APIKeyValid']) {
                echo $divHeader . $noticeCorrectAPIKeyContent . $divFooter;
            } else {
                echo $divWarningHeader . $noticeWrongAPIKeyContent . $divFooter;
            }
        }
        if($hook_suffix == 'plugins.php' && !$this->_verifiedKey) { echo  $noticeInvalidKeyContent; }
    }

    public function getQuotaInformation($apiKey = null) {

        if(is_null($apiKey)) { $apiKey = $this->_apiKey; }

        $requestURL = 'https://api.shortpixel.com/api-status.php?key='.$apiKey;
        $args = array('timeout'=> SP_MAX_TIMEOUT, 'sslverify'   => false);
        $response = wp_remote_get($requestURL, $args);

        $defaultData = array(
            "APIKeyValid" => false,
            "APICallsMade" => 'Information unavailable. Please check your API key.',
            "APICallsQuota" => 'Information unavailable. Please check your API key.');

        if(is_object($response) && get_class($response) == 'WP_Error') {
            return $defaultData;
        }

        if($response['response']['code'] != 200) {
            return $defaultData;
        }

        $data = $response['body'];
        $data = $this->parseJSON($data);

        if(empty($data)) { return $defaultData; }

        if($data->Status->Code == '-401') { return $defaultData; }

        return array(
            "APIKeyValid" => true,
            "APICallsMade" => number_format($data->APICallsMade) . ' images',
            "APICallsQuota" => number_format($data->APICallsQuota) . ' images'
        );


    }

    public function generateCustomColumn( $column_name, $id ) {
        if( 'wp-shortPixel' == $column_name ) {

            $data = wp_get_attachment_metadata($id);

            if ( isset( $data['ShortPixelImprovement'] ) ) {

                if(isset($meta['ShortPixel']['BulkProcessing'])) {
                    print 'Waiting for bulk processing';
                    return;
                }

                print $data['ShortPixelImprovement'];
                if(is_numeric($data['ShortPixelImprovement'])) print '%';
                print " | <a href=\"admin.php?action=shortpixel_manual_optimize&amp;attachment_ID={$id}\">Optimize now</a>";
                print " | <a href=\"admin.php?action=shortpixel_restore_backup&amp;attachment_ID={$id}\">Restore backup</a>";
                return;
            }

            if(isset($data['ShortPixel']['WaitingProcessing'])) {
                print 'Image waiting to be processed';
                return;
            } else {
                if ( wp_attachment_is_image( $id ) ) {
                    print 'Image not processed';
                    print " | <a href=\"admin.php?action=shortpixel_manual_optimize&amp;attachment_ID={$id}\">Optimize now</a>";
                    return;
                }
            }
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

    static public function folderSize($path) {
        $total_size = 0;
        if(file_exists($path)) {
            $files = scandir($path);
        } else {
            return $total_size;
        }
        $cleanPath = rtrim($path, '/'). '/';
        foreach($files as $t) {
            if ($t<>"." && $t<>"..") {
                $currentFile = $cleanPath . $t;
                if (is_dir($currentFile)) {
                    $size = foldersize($currentFile);
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


}

$pluginInstance = new WPShortPixel();
global $pluginInstance;

?>

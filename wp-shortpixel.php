<?php
/**
 * Plugin Name: ShortPixel Image Optimiser
 * Plugin URI: https://shortpixel.com/
 * Description: ShortPixel is an image compression tool that helps improve your website performance. The plugin optimises images automatically using both lossy and lossless compression. Resulting, smaller, images are no different in quality from the original. To install: 1) Click the "Activate" link to the left of this description. 2) <a href="https://shortpixel.com/free-sign-up" target="_blank">Free Sign up</a> for your unique API Key . 3) Check your email for your API key. 4) Use your API key to activate ShortPixel plugin in the 'Plugins' menu in WordPress. 5) Done!
 * Version: 1.2.0
 * Author: ShortPixel
 * Author URI: https://shortpixel.com
 */

require_once('shortpixel_api.php');
require_once( ABSPATH . 'wp-admin/includes/image.php' );

class WPShortPixel {

    private $_apiInterface = null;
    private $_apiKey = '';
    private $_compressionType = '';
    private $_processThumbnails = 1;
    private $_verifiedKey = false;

    public function __construct() {
        define('SP_DEBUG', false);
        define('SP_LOG', false);
        define('SP_MAX_TIMEOUT', 10);

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


        add_action( 'admin_footer', array( &$this, 'my_action_javascript') );
        add_action( 'wp_ajax_my_action', array( &$this, 'handleImageProcessing') );
    }

    public function populateOptions() {

        if(get_option('wp-short-pixel-apiKey') != false) {
            $this->_apiKey = get_option('wp-short-pixel-apiKey');
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

    function my_action_javascript() { ?>
        <script type="text/javascript" >
            jQuery(document).ready(function($) {
                var data = {
                    'action': 'my_action',
                    'whatever': 1234
                };
                // since 2.8 ajaxurl is always defined in the admin header and points to admin-ajax.php
                $.post(ajaxurl, data, function(response) {
                    console.log('Server response: ' + response);
                });
            });
        </script> <?php
    }

    //handling older
    public function WPShortPixel() {
        $this->__construct();
    }

    public function handleImageUpload($meta, $ID = null) {
        $meta['ShortPixel']['WaitingProcessing'] = true;
        return $meta;
    }

    public function handleImageProcessing($ID = null) {
        //query database for first found entry that needs processing
        global  $wpdb;
        $qry = "SELECT post_id FROM " . $wpdb->prefix . "postmeta WHERE meta_value LIKE '%\"WaitingProcessing\";b:1;%' LIMIT 1";
        $ID = $wpdb->get_var($qry);

        if(empty($ID)) { die; }

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

        //check bulk processing
        $bulkLog = get_option('bulkProcessingLog');
        if(isset($bulkLog)) {
            if(array_key_exists($ID, $bulkLog['toDo'])) {
                unset($bulkLog['toDo'][$ID]);
            }
        }

        if(empty($bulkLog['toDo'])) { delete_option('bulkProcessingLog'); }
        else { update_option('bulkProcessingLog', $bulkLog); }

        $meta['ShortPixelImprovement'] = $result->PercentImprovement;

        wp_update_attachment_metadata($ID, $meta);
        echo "Processing done succesfully for image #{$ID}";
        die();
    }

    public function registerSettingsPage() {
        add_options_page( 'ShortPixel Settings', 'ShortPixel', 'manage_options', 'wp-shortpixel', array($this, 'renderSettingsMenu'));
    }

    function registerAdminPage( ) {
        add_media_page( 'ShortPixel Bulk Process', 'Bulk ShortPixel', 'edit_others_posts', 'wp-short-pixel-bulk', array( &$this, 'bulkProcesss' ) );
    }

    public function bulkProcesss() {
        echo '<h1>ShortPixel Bulk Processing</h1>';

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
            echo "<p>Bulk processing started and it may take a while to be completed.</p>";

            $imagesLeft = count($currentBulkProcessingStatus["toDo"]);
            $totalImages = $currentBulkProcessingStatus['total'];

            echo "<p>{$imagesLeft} out of {$totalImages} images left to process.</p>";

            echo '
                <a class="button button-secondary" href="' . get_admin_url()  . 'upload.php?page=wp-short-pixel-bulk">Check Status<a/>
                <a class="button button-secondary" href="' . get_admin_url() .  'upload.php">Media Library</a>
            ';
        } else {
            echo $this->getBulkProcessingForm(count($attachments));
        }



        //TO DO: find a way to track when bulk processing is running and update a log for customer

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
                update_option('wp-short-process_thumbnails', $this->_processThumbnails);
            }
        }

        $checked = '';
        if($this->_processThumbnails) { $checked = 'checked'; }

        echo '<h1>ShortPixel Image Optimiser Settings</h1>';
        echo '<p>
                <a href="https://shortpixel.com">ShortPixel.com</a> |
                <a href="https://wordpress.org/plugins/shortpixel-image-optimiser/installation/">Installation </a> |
                <a href="https://wordpress.org/support/plugin/shortpixel-image-optimiser">Support </a>
              </p>';
        echo '<p>New images uploaded to the Media Library will be optimized automatically.<br/>If you have existing images you would like to optimize, you can use the <a href="/upload.php?page=wp-short-pixel-bulk">Bulk Optimize tool</a>.</p>';

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
            $formHTML .= '<tr><td style="padding-left: 0px;" colspan="2">Don’t have an API Key? <a href="https://shortpixel.com/wp-apikey" target="_blank">Sign up, it’s free.</a></td></tr>';
        }

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
<th scope="row"><label for="thumbnails">Compress also image thumbnails</label></th>
<td><input name="thumbnails" type="checkbox" id="thumbnails" {$checked}></td>
</tr>
</tbody></table>
<p class="submit">
    <input type="submit" name="submit" id="submit" class="button button-primary" title="Save Changes" value="Save Changes">
    <input type="submit" name="bulk-process" id="bulk-process" class="button button-primary" title="Process all the images in your Media Library" value="Bulk Process">
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
        alert('Select Media/Bulk Short Pixel to reprocess all the images');
    };
}
</script>
HTML;
        echo $formHTML;

        $fileCount = get_option('wp-short-pixel-fileCount');
        $savedSpace = self::formatBytes(get_option('wp-short-pixel-savedSpace'),2);
        $averageCompression = round(get_option('wp-short-pixel-averageCompression'),2);
        $savedBandwidth = self::formatBytes(get_option('wp-short-pixel-savedSpace') * 1000,2);

        $quotaData = $this->getQuotaInformation();

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
</tbody></table>
<p>* Saved bandwidth is calculated at 100,000 impressions/image</p>
HTML;
        echo $statHTML;
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

        $divHeader = '<div class="updated">';
        $divWarningHeader = '<div class="update-nag">';
        $divFooter = '</div>';

        $noticeActivationContent = 'ShortPixel plugin activated! Get an API key <a href="https://shortpixel.com/wp-apikey" target="_blank">here</a>. Sign up, it’s free.';
        $noticeWrongAPIKeyContent = 'API Key invalid!';
        $noticeCorrectAPIKeyContent = 'API Key valid!';

        if($hook_suffix == 'settings_page_wp-shortpixel' && !empty($_POST)) {
            $keyCheckData = $this->getQuotaInformation($_POST['key']);
            if($keyCheckData['APIKeyValid']) {
                echo $divHeader . $noticeCorrectAPIKeyContent . $divFooter;
            } else {
                echo $divWarningHeader . $noticeWrongAPIKeyContent . $divFooter;
            }
        }
        if($hook_suffix == 'plugins.php' && $_GET['activate']) { echo  $divHeader . $noticeActivationContent . $divFooter; }
    }

    static public function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    public function getQuotaInformation($apiKey = null) {

        if(is_null($apiKey)) { $apiKey = $this->_apiKey; }

        $requestURL = 'https://api.shortpixel.com/api-status.php?key='.$apiKey;
        $args = array('timeout'=> SP_MAX_TIMEOUT);
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

                if(isset($data['ShortPixel']['WaitingProcessing']) && $data['ShortPixel']['WaitingProcessing']) {
                    print 'Waiting for bulk processing';
                    return;
                }

                print $data['ShortPixelImprovement'];
                if(is_numeric($data['ShortPixelImprovement'])) print '%';
                return;
            }

            if(isset($data['ShortPixel']['WaitingProcessing']) && $data['ShortPixel']['WaitingProcessing']) {
                print 'Image waiting to be processed';
                return;
            } else {
                if ( wp_attachment_is_image( $id ) ) {
                    print 'Image not processed';
                    return;
                }
            }
        }
    }

    public function columns( $defaults ) {
        $defaults['wp-shortPixel'] = 'Short Pixel Compression';
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
}

$pluginInstance = new WPShortPixel();
global $pluginInstance;

?>

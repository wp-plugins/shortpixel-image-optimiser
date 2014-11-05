<?php
/**
 * Plugin Name: ShortPixel Image Optimiser
 * Plugin URI: https://shortpixel.com/
 * Description: ShortPixel is an image compression tool that helps improve your website performance.
 * Version: 1.0
 */

require_once('shortpixel_api.php');
require_once( ABSPATH . 'wp-admin/includes/image.php' );

class WPShortPixel {

    private $_apiInterface = null;
    private $_apiKey = '';
    private $_compressionType = '';
    private $_processThumbnails = 0;

    public function __construct() {
        define('SP_DEBUG', false);
        define('SP_LOG', false);
        define('SP_MAX_TIMEOUT', 10);

        $this->populateOptions();

        $this->_apiInterface = new shortpixel_api($this->_apiKey, $this->_compressionType);

        //add hook for image upload processing
        add_filter( 'wp_generate_attachment_metadata', array( &$this, 'handleImageUpload' ), 10, 2 );
        add_filter( 'manage_media_columns', array( &$this, 'columns' ) );
        add_action( 'manage_media_custom_column', array( &$this, 'generateCustomColumn' ), 10, 2 );



        //add settings page
        add_action( 'admin_menu', array( &$this, 'registerSettingsPage' ) );
        add_action( 'admin_menu', array( &$this, 'registerAdminPage' ) );

    }

    public function populateOptions() {

        if(get_option('wp-short-pixel-apiKey') != false) {
            $this->_apiKey = get_option('wp-short-pixel-apiKey');
        }

        if(get_option('wp-short-pixel-compression') != false) {
            $this->_compressionType = get_option('wp-short-pixel-compression');
        }

        if(get_option('wp-short-process_thumbnails') != false) {
            $this->_processThumbnails = get_option('wp-short-process_thumbnails');
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

    //handling older
    public function WPShortPixel() {
        $this->__construct();
    }

    public function handleImageUpload($meta, $ID = null) {
        $imageURL =  wp_get_attachment_url($ID);
        $imagePath = get_attached_file($ID);

        $result = $this->_apiInterface->processImage($imageURL, $imagePath, $ID);

        if(is_string($result)) {
            $meta['ShortPixelImprovement'] = $result;
            return $meta;
        } else {
            $processThumbnails = get_option('wp-short-process_thumbnails');

            //handle the rest of the thumbnails generated by WP
            if($processThumbnails && $result && !empty($meta['sizes'])) {
                foreach($meta['sizes'] as $thumbnailInfo) {
                    $thumbURL = str_replace(basename($imagePath), $thumbnailInfo['file'], $imageURL);
                    $thumbPath = str_replace(basename($imagePath), $thumbnailInfo['file'], $imagePath);
                    $this->_apiInterface->processImage($thumbURL, $thumbPath);
                }
            }
        }

        $meta['ShortPixelImprovement'] = $result->PercentImprovement;

        return $meta;
    }

    public function registerSettingsPage() {
        add_options_page( 'ShortPixel Settings', 'ShortPixel', 'manage_options', 'wp-shortpixel', array($this, 'renderSettingsMenu'));
    }

    function registerAdminPage( ) {
        add_media_page( 'ShortPixel Bulk Process', 'Bulk ShortPixel', 'edit_others_posts', 'wp-short-pixel-bulk', array( &$this, 'bulkProcesss' ) );
    }

    public function bulkProcesss() {
        echo '<h1>ShortPixel bulk compression</h1>';

        if ( function_exists( 'apache_setenv' ) ) {
            @apache_setenv('no-gzip', 1);
        }
        @ini_set('output_buffering','on');
        @ini_set('zlib.output_compression', 0);
        @ini_set('implicit_flush', 1);

        $attachments = null;
        $attachments = get_posts( array(
            'numberposts' => -1,
            'post_type' => 'attachment',
            'post_mime_type' => 'image'
        ));

        if(isset($_POST['bulkProcess'])) {

            @ob_implicit_flush( true );
            @ob_end_flush();

            foreach( $attachments as $attachment ) {

                //public function processImage($url, $filePath, $ID = null, $time = 0) {
                $imageURL =  wp_get_attachment_url($attachment->ID);
                $imagePath = get_attached_file($attachment->ID);

                $processingResult = $this->_apiInterface->processImage($imageURL, $imagePath, $attachment->ID);

                if(!is_object($processingResult)) {
                    echo "Error! Image " . basename($imagePath) . " could not be processed<br/>";
                }

                if($processingResult->Status->Code == 1) {
                    echo "Image " . basename($imagePath) . " scheduled for processing.<br/>";
                } elseif($processingResult->Status->Code == 2) {
                    echo "Image " . basename($imagePath) . " processed succesfully.<br/>";
                } else {
                    echo "Error! Image " . basename($imagePath) . " could not be processed<br/>";
                }
                sleep(1);
            }

            @ob_flush();
            flush();

        } else {
            if(count($attachments)) {
                echo $this->getBulkProcessingForm(count($attachments));
            } else {
                echo "It appear that you have no images uploaded yet.</br>";
            }
        }

    }

    public function renderSettingsMenu() {
        if ( !current_user_can( 'manage_options' ) )  {
            wp_die('You do not have sufficient permissions to access this page.');
        }

        if(isset($_POST['submit'])) {
            //handle save options
            update_option('wp-short-pixel-apiKey', $_POST['key']);
            $this->_apiKey = $_POST['key'];
            $this->_apiInterface->setApiKey($this->_apiKey);
            update_option('wp-short-pixel-compression', $_POST['compressionType']);
            $this->_compressionType = $_POST['compressionType'];
            $this->_apiInterface->setCompressionType($this->_compressionType);
            if(isset($_POST['thumbnails'])) { $this->_processThumbnails = 1; } else { $this->_processThumbnails = 0; }
            update_option('wp-short-process_thumbnails', $this->_processThumbnails);
        }

        $checked = '';
        if($this->_processThumbnails) { $checked = 'checked'; }

        echo '<h1>ShortPixel Options</h1>';
        echo '<p>ShortPixel improves website performance by reducing the images’ size.<BR>Configure ShortPixel plugin to compress both past and new past images and optimise your website.</p>';

        $formHTML = <<< HTML
<form name='wp_shortpixel_options' action=''  method='post' id='wp_shortpixel_options'>
<table class="form-table">
<tbody><tr>
<th scope="row"><label for="key">API Key:</label></th>
<td><input name="key" type="text" id="key" value="{$this->_apiKey}" class="regular-text"></td>
</tr>
<tr><td style="padding-left: 0px;" colspan="2">Don’t have an API Key? <a href="https://shortpixel.com/wp-apikey" target="_blank">Sign up, it’s free.</a></td></tr>
<tr><th scope="row">
    <label for="compressionType">Compression type: <span title="Lossy compression. Lossy has a better compression rate than lossless compression. The resulting image is not 100% identical with the original. Works well for photos taken with your camera.
    Lossless compression. The shrunk image will be identical with the original and smaller in size. Use this when you do not want to loose any of the original image's details. Works best for technical drawings, clip art and comics.">?</span></label>

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
<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes"></p>
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
<td>{$quotaData['APICallsQuota']} images</td>
</tr>
<tr>
<th scope="row"><label for="usedQUota">Used Quota:</label></th>
<td>{$quotaData['APICallsMade']} images</td>
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
You have {$imageCount} images in your library. </br>
</br>
<form action='' method="POST" >
<input type="submit" name="bulkProcess" id="bulkProcess" class="button button-primary" value="Compress all your images">
</form>

HTML;
    }

    static public function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    public function getQuotaInformation() {
        $requestURL = 'https://api.shortpixel.com/api-status.php?key='.$this->_apiKey;
        $args = array('timeout'=> SP_MAX_TIMEOUT);
        $response = wp_remote_get($requestURL, $args);

        $defaultData = array("APICallsMade" => 'Information unavailable. Please check your API key.'
        , "APICallsQuota" => 'Information unavailable. Please check your API key.');

        if(is_object($response) && get_class($response) == 'WP_Error') {
            return $defaultData;
        }

        if($response['response']['code'] != 200) {
            return $defaultData;
        }

        $data = $response['body'];
        $data = $this->parseJSON($data);

        if(empty($data)) { return $defaultData; }

        return array("APICallsMade" => $data->APICallsMade, "APICallsQuota" => $data->APICallsQuota);


    }

    public function generateCustomColumn( $column_name, $id ) {
        if( 'wp-shortPixel' == $column_name ) {
            $data = wp_get_attachment_metadata($id);
            if ( isset( $data['ShortPixelImprovement'] ) ) {
                print $data['ShortPixelImprovement'];
                if(is_numeric($data['ShortPixelImprovement'])) print '%';
            } else {
                if ( wp_attachment_is_image( $id ) ) {
                    print 'Image not processed';
                }
            }
        }
    }

    public function columns( $defaults ) {
        $defaults['wp-shortPixel'] = 'Short Pixel Compression';
        return $defaults;
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

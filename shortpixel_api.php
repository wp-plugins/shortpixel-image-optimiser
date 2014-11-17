<?php
if ( !function_exists( 'download_url' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/file.php' );
}

class shortpixel_api {

    private $_apiKey = '';
    private $_compressionType = '';
    private $_maxAttempts = 10;
    private $_apiEndPoint = 'https://api.shortpixel.com/v1/reducer.php';

    public function setCompressionType($compressionType)
    {
        if($compressionType == 'lossy') { $this->_compressionType = 1; }
        else { $this->_compressionType = 0; }
    }

    public function getCompressionType()
    {
        return $this->_compressionType;
    }

    public function setApiKey($apiKey)
    {
        $this->_apiKey = $apiKey;
    }

    public function getApiKey()
    {
        return $this->_apiKey;
    }

    public function __construct($apiKey, $compressionType) {
        $this->_apiKey = $apiKey;
        $this->setCompressionType($compressionType);

        add_action('processImageAction', array(&$this, 'processImageAction'), 10, 4);
    }

    public function processImageAction($url, $filePath, $ID, $time) {
        $this->processImage($url, $filePath, $ID, $time);
    }

    public function doRequests($url, $filePath, $ID = null, $time = 0) {
        $requestURL = $this->_apiEndPoint . '?key=' . $this->_apiKey . '&lossy=' . $this->_compressionType . '&url=';
        $requestURL = $requestURL . urlencode($url);

        $args = array('timeout'=> SP_MAX_TIMEOUT);

        $response = wp_remote_get($requestURL, $args);

        if(is_object($response) && get_class($response) == 'WP_Error') {
            return false;
        }

        return $response;
    }

    public function parseResponse($response) {
        $data = $response['body'];
        $data = str_replace('Warning: Division by zero in /usr/local/important/web/api.shortpixel.com/lib/functions.php on line 33', '', $data);
        $data = $this->parseJSON($data);
        return $data;
    }

    //handles the processing of the image using the ShortPixel API
    public function processImage($url, $filePath, $ID = null, $time = 0) {

        $response = $this->doRequests($url, $filePath, $ID, $time);

        if(!$response) return $response;

        if($response['response']['code'] != 200) {
            printf('Web service did not respond. Please try again later.');
            return false;
            //error
        }

        $data = $this->parseResponse($response);

        switch($data->Status->Code) {
            case 1:
                //handle image has been scheduled
                sleep(1);
                return $this->processImage($url, $filePath, $ID, $time);
                break;
            case 2:
                //handle image has been processed
                $this->handleSuccess($data, $url, $filePath, $ID);
                break;
            case -16:
                return 'Quota exceeded</br>';
            case -17:
                return 'Wrong API Key</br>';
            default:
                //handle error
                return 'An error occurred while processing this image. Please try uploading it again.</br>';
        }

        return $data;
    }


    public function handleSuccess($callData, $url, $filePath, $ID) {

        if(property_exists($callData, 'LossySize')) {
            //lossy
            $correctFileSize = $callData->LossySize;
            $tempFile = download_url(str_replace('https://','http://',urldecode($callData->LossyURL)));
        } else {
            //lossless
            $correctFileSize = $callData->LoselessSize;
            $tempFile = download_url(str_replace('https://','http://',urldecode($callData->LosslessURL)));
        }

        if ( is_wp_error( $tempFile ) ) {
            @unlink($tempFile);
            return printf("Error downloading file (%s)", $tempFile->get_error_message());
        }

        //check response so that download is OK
        if(filesize($tempFile) != $correctFileSize) {
            return printf("Error downloading file - incorrect file size");
        }

        if (!file_exists($tempFile)) {
            return printf("Unable to locate downloaded file (%s)", $tempFile);
        }

        @unlink( $filePath );
        $success = @rename( $tempFile, $filePath );

        if (!$success) {
            $copySuccess = copy($tempFile, $filePath);
            unlink($tempFile);
        }

        if($success || $copySuccess) {
            //update statistics
            if(isset($callData->LossySize)) {
                $savedSpace = $callData->OriginalSize - $callData->LossySize;
            } else {
                $savedSpace = $callData->OriginalSize - $callData->LoselessSize;
            }

            update_option(
                'wp-short-pixel-savedSpace',
                get_option('wp-short-pixel-savedSpace') + $savedSpace
            );
            $averageCompression = get_option('wp-short-pixel-averageCompression') * get_option('wp-short-pixel-fileCount');
            $averageCompression += $callData->PercentImprovement;
            $averageCompression = $averageCompression /  (get_option('wp-short-pixel-fileCount') + 1);
            update_option('wp-short-pixel-averageCompression', $averageCompression);
            update_option('wp-short-pixel-fileCount', get_option('wp-short-pixel-fileCount')+1);

            //update metadata
            if(isset($ID)) {
                $meta = wp_get_attachment_metadata($ID);
                $meta['ShortPixelImprovement'] = $callData->PercentImprovement;
                wp_update_attachment_metadata($ID, $meta);
            }
        }
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

<?php

class ShortPixelView {
    
    private $ctrl;
    
        //handling older
    public function ShortPixelView($controller) {
        $this->__construct($controller);
    }

    public function __construct($controller) {
        $this->ctrl = $controller;
    }
    
    public function displayQuotaExceededAlert($quotaData) 
    { ?>    
        <br/>
        <div class="wrap" style="background-color: #fff; border-left: 4px solid #ff0000; box-shadow: 0 1px 1px 0 rgba(0, 0, 0, 0.1); padding: 1px 12px;">
            <h3>Quota Exceeded</h3>
            <p>The plugin has optimized <strong><?=number_format($quotaData['APICallsMadeNumeric'] + $quotaData['APICallsMadeOneTimeNumeric'])?> images</strong> and stopped because it reached the available quota limit.
            <?php if($quotaData['totalProcessedFiles'] < $quotaData['totalFiles']) { ?>
                <strong><?=number_format($quotaData['mainFiles'] - $quotaData['mainProcessedFiles'])?> images and 
                <?=number_format(($quotaData['totalFiles'] - $quotaData['mainFiles']) - ($quotaData['totalProcessedFiles'] - $quotaData['mainProcessedFiles']))?> thumbnails</strong> are not yet optimized by ShortPixel.
            <?php } ?></p>
            <p>It’s simple to upgrade, just <a href='https://shortpixel.com/login/<?=$this->ctrl->getApiKey()?>' target='_blank'>log into your account</a> and see the available options.
            You can immediately start processing 5,000 images/month for &#36;4,99, choose another plan that suits you or <a href='https://shortpixel.com/contact' target='_blank'>contact us</a> for larger compression needs.</p>
            <p>Get more image credits by referring ShortPixel to your friends! <a href="https://shortpixel.com/login/<?=$this->ctrl->getApiKey()?>/tell-a-friend" target="_blank">Check your account</a> for your unique referral link. For each user that joins, you will receive +100 additional image credits/month.</p>
            <input type='button' name='checkQuota' class='button button-primary' value='Confirm New Quota' onclick="javascript:window.location.reload();" style="margin-bottom:12px;">
        </div> <?php 
    }
    
    public static function displayApiKeyAlert() 
    { ?>
        <p>In order to start the optimization process, you need to validate your API key in the <a href="options-general.php?page=wp-shortpixel">ShortPixel Settings</a> page in your WordPress Admin.</p>
        <p>If you don’t have an API Key, you can get one delivered to your inbox, for free.</p>
        <p>Please <a href="https://shortpixel.com/wp-apikey" target="_blank">sign up</a> to get your API key.</p>
    <?php
    }
    
    public static function displayActivationNotice($when = 'activate')  { ?>
        <div class='notice notice-warning' id='short-pixel-notice-<?=$when?>'>
            <?php if($when != 'activate') { ?>
            <div style="float:right;"><a href="javascript:dismissShortPixelNotice('<?=$when?>')" class="button" style="margin-top:10px;">Dismiss</a></div>
            <?php } ?>
            <h3>ShortPixel Optimization</h3> <?php
            switch($when) {
                case '2h' : 
                    echo "Action needed. Please <a href='https://shortpixel.com/wp-apikey' target='_blank'>get your API key</a> to activate your ShortPixel plugin.<BR><BR>";
                    break;
                case '3d':
                    echo "Your image gallery is not optimized. It takes 2 minutes to <a href='https://shortpixel.com/wp-apikey' target='_blank'>get your API key</a> and activate your ShortPixel plugin.<BR><BR>";
                    break;
                case 'activate':
                    self::displayApiKeyAlert();
                    break;
            }
            ?>
        </div>
    <?php
    }
    
    public function displayBulkProcessingForm($quotaData,  $thumbsProcessedCount, $under5PercentCount, $bulkRan, $averageCompression, $filesOptimized, $savedSpace, $percent) {
        ?>
        <div class="wrap short-pixel-bulk-page">
            <h1>Bulk Image Optimization by ShortPixel</h1>
        <?php
        if ( !$bulkRan ) { ?>
            <p>You have <?=number_format($quotaData['mainFiles'])?> images in your Media Library and <?=number_format($quotaData['totalFiles'] - $quotaData['mainFiles'])?> smaller thumbnails, associated to these images.</p>
            <?php if($quotaData["totalProcessedFiles"] > 0) { ?>
            <p>From these, <?=number_format($quotaData['mainProcessedFiles'])?> images and <?=number_format($quotaData['totalProcessedFiles'] - $quotaData['mainProcessedFiles'])?> thumbnails were already processed by ShorPixel</p>
            <?php } ?>
            <p>If the box below is checked, <b>ShortPixel will process a total of <?=number_format($quotaData['totalFiles'] - $quotaData['totalProcessedFiles'])?> images.</b> However, images with less than 5% optimization will not be counted out of your quota, so the final number of counted images could be smaller.</p>
            <p>Thumbnails are important because they are displayed on most of your website's pages and they may generate more traffic than the originals. Optimizing thumbnails will improve your overall website speed. However, if you don't want to optimize thumbnails, please uncheck the box below.</p>

            <form action='' method='POST' >
                <input type='checkbox' name='thumbnails' <?=$this->ctrl->processThumbnails() ? "checked":""?>> Include thumbnails
                <p>The plugin will replace the original images with the optimized ones starting with the newest images added to your Media Library. You will be able to pause the process anytime.</p>
                <?=$this->ctrl->backupImages() ? "<p>Your original images will be stored in a separate back-up folder.</p>" : ""?> 
                <input type='submit' name='bulkProcess' id='bulkProcess' class='button button-primary' value='Start Optimizing'>
            </form>
            <?php
        } elseif($percent) // bulk is paused
        { ?>
            <p>Bulk processing is paused until you resume the optimization process.</p>
            <?=$this->displayBulkProgressBar(false, $percent, "")?>
            <p>Please see below the optimization status so far:</p>
            <?=$this->displayBulkStats($quotaData['totalProcessedFiles'], $quotaData['mainProcessedFiles'], $under5PercentCount, $averageCompression, $savedSpace)?>
            <?php if($quotaData['totalProcessedFiles'] < $quotaData['totalFiles']) { ?>
                <p><?=number_format($quotaData['mainFiles'] - $quotaData['mainProcessedFiles'])?> images and 
                <?=number_format(($quotaData['totalFiles'] - $quotaData['mainFiles']) - ($quotaData['totalProcessedFiles'] - $quotaData['mainProcessedFiles']))?> thumbnails are not yet optimized by ShortPixel.</p>
            <?php } ?>
            <p>You can continue optimizing your Media Gallery from where you left, by clicking the Resume processing button. Already optimized images will not be reprocessed.</p>
        <?php
        } else { ?>
            <p>Congratulations, your media library has been successfully optimized!</p>
            <?=$this->displayBulkStats($quotaData['totalProcessedFiles'], $quotaData['mainProcessedFiles'], $under5PercentCount, $averageCompression, $savedSpace)?>
            <p>Go to the ShortPixel <a href='<?=get_admin_url()?>options-general.php?page=wp-shortpixel#facts'>Stats</a> and see all your websites' optimized stats. Download your detailed <a href="https://api.shortpixel.com/v2/report.php?key=<?=$this->ctrl->getApiKey()?>">Optimization Report</a> to check your image optimization statistics for the last 40 days</p>
            <?php if($quotaData['totalProcessedFiles'] < $quotaData['totalFiles']) { ?>
                <p><?=number_format($quotaData['mainFiles'] - $quotaData['mainProcessedFiles'])?> images and 
                <?=number_format(($quotaData['totalFiles'] - $quotaData['mainFiles']) - ($quotaData['totalProcessedFiles'] - $quotaData['mainProcessedFiles']))?> thumbnails are not yet optimized by ShortPixel.</p>
            <?php }
            $failed = $this->ctrl->getPrioQ()->getFailed();
            if(count($failed)) { ?>
                <p>The following images are not writable so ShortPixel could not update the files. Please check the rights for these and then restart the optimization process.</p>
                <?=$this->displayFailed($failed)?>
            <?php } ?>
            <p>Restart the optimization process for new images added to your library by clicking the button below. Already optimized images will not be reprocessed.
            <form action='' method='POST' >
                <input type='checkbox' name='thumbnails' <?=$this->ctrl->processThumbnails() ? "checked":""?>> Include thumbnails<br><br>
                <input type='submit' name='bulkProcess' id='bulkProcess' class='button button-primary' value='Restart Optimizing'>
            </form>
        <?php } ?>
        </div>
        <?php
    }

    public function displayBulkProcessingRunning($percent, $message) {
        ?>
        <div class="wrap short-pixel-bulk-page">
            <h1>Bulk Image Optimization by ShortPixel</h1>
            <p>Bulk optimization has started.<br>
               This process will take some time, depending on the number of images in your library. In the meantime, you can continue using the admin as usual.<br>
               However, <strong>if you close the WordPress admin, the bulk processing will pause</strong> until you open the admin again. </p>
            <?=$this->displayBulkProgressBar(true, $percent, $message)?>
            <div class="bulk-progress bulk-slider-container">
                <div style="margin-bottom: 10px;"><span class="short-pixel-block-title">Just optimized:</span></div>
                <div class="bulk-slider">
                    <div class="bulk-slide" id="empty-slide">
                        <div class="img-original">
                            <div><img class="bulk-img-orig" src=""></div>
                          <div>Original image</div>
                        </div>
                        <div class="img-optimized">
                            <div><img class="bulk-img-opt" src=""></div>
                          <div>Optimized image</div>
                        </div>
                        <div class="img-info">
                            <div style="font-size: 14px; line-height: 10px; margin-bottom:16px;">Optimized by:</div>
                            <span class="bulk-opt-percent"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function displayBulkProgressBar($running, $percent, $message) {
        $percentBefore = $percentAfter = '';
        if($percent > 24) {
            $percentBefore = $percent . "%";
        } else {
            $percentAfter = $percent . "%";
        }
        ?>
            <div class="bulk-progress">
                <div id="bulk-progress" class="progress" >
                    <div class="progress-img" style="left: <?=$percent?>%;">
                        <img src="<?=plugins_url( 'img/slider.png', __FILE__ )?>">
                        <span><?=$percentAfter?></span>
                    </div>
                    <div class="progress-left" style="width: <?=$percent?>%"><?=$percentBefore?></div>
                </div>
                <div class="bulk-estimate">
                    &nbsp;<?=$message?>
                </div>
            <form action='' method='POST' style="display:inline;">
                <input type="submit" class="button button-primary bulk-cancel"  onclick="clearBulkProcessor();"
                       name="<?=$running ? "bulkProcessPause" : "bulkProcessResume"?>" value="<?=$running ? "Pause" : "Resume Processing"?>"/>
            </form>
            </div>
        <?php
    }
    
    public function displayBulkStats($totalOptimized, $mainOptimized, $under5PercentCount, $averageCompression, $savedSpace) {
        ?>
            <div class="bulk-progress bulk-stats">
                <div class="label">Processed Images and PDFs:</div><div class="stat-value"><?=number_format($mainOptimized)?></div><br>
                <div class="label">Processed Thumbnails:</div><div class="stat-value"><?=number_format($totalOptimized - $mainOptimized)?></div><br>
                <div class="label totals">Total files processed:</div><div class="stat-value"><?=number_format($totalOptimized)?></div><br>
                <div class="label totals">Minus files with <5% optimization (free):</div><div class="stat-value"><?=number_format($under5PercentCount)?></div><br><br>
                <div class="label totals">Used quota:</div><div class="stat-value"><?=number_format($totalOptimized - $under5PercentCount)?></div><br>
                <br>
                <div class="label">Average optimization:</div><div class="stat-value"><?=$averageCompression?>%</div><br>
                <div class="label">Saved space:</div><div class="stat-value"><?=$savedSpace?></div>
            </div>
        <?php
    }
     
    public function displayFailed($failed) {
        ?>
            <div class="bulk-progress bulk-stats">
                <?php foreach($failed as $fail) { 
                $meta = wp_get_attachment_metadata($fail);
                if(isset($meta["ShortPixelImprovement"]) && is_numeric($meta["ShortPixelImprovement"])){
                    $this->ctrl->getPrioQ()->removeFromFailed($fail);
                } else {
                    ?> <div class="label"><a href="/wp-admin/post.php?post=<?=$fail?>&action=edit"><?=substr($meta["file"], 0, 80)?> - ID: <?=$fail?></a></div><br/>
                <?php } 
                }?>
            </div>
        <?php
    }

    public function displaySettingsForm($quotaData) {
        $checked = ($this->ctrl->processThumbnails() ? 'checked' : '');
        $checkedBackupImages = ($this->ctrl->backupImages() ? 'checked' : '');
        $cmyk2rgb = ($this->ctrl->getCMYKtoRGBconversion() ? 'checked' : '');
        $resize = ($this->ctrl->getResizeImages() ? 'checked' : '');
        $resizeDisabled = ($this->ctrl->getResizeImages() ? '' : 'disabled');        
        $minSizes = $this->ctrl->getMaxIntermediateImageSize();
        ?>
        <form name='wp_shortpixel_options' action=''  method='post' id='wp_shortpixel_options'>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row"><label for="key">API Key:</label></th>
                        <td><input name="key" type="text" id="key" value="<?= $this->ctrl->getApiKey() ?>" class="regular-text">
                            <input type="hidden" name="validate" id="valid" value=""/>
                            <button type="button" id="validate" class="button button-primary" title="Validate the provided API key"
                                    onclick="validateKey()">Validate</button>
                        </td>
                    </tr>
        <?php if (!$this->ctrl->getVerifiedKey()) { //if invalid key we display the link to the API Key ?>
                    <tr>
                        <td style="padding-left: 0px;" colspan="2">Don’t have an API Key? 
                            <a href="https://shortpixel.com/wp-apikey<?= $this->ctrl->getAffiliateSufix() ?>" target="_blank">Sign up, it’s free.</a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </form>
        <?php } else { //if valid key we display the rest of the options ?>
                    <tr>
                        <th scope="row">
                            <label for="compressionType">Compression type:</label>
                        </th>
                        <td>
                            <input type="radio" name="compressionType" value="1" <?= $this->ctrl->getCompressionType() == 1 ? "checked" : "" ?>>Lossy</br></br>
                            <input type="radio" name="compressionType" value="0" <?= $this->ctrl->getCompressionType() != 1 ? "checked" : "" ?>>Lossless
                        </td>
                    </tr>
                </tbody>
            </table>
            <p style="color: #818181;"> <b>Lossy compression: </b>lossy has a better compression rate than lossless compression.</br>The resulting image
            is not 100% identical with the original. Works well for photos taken with your camera.</br></br>
            <b>Lossless compression: </b> the shrunk image will be identical with the original and smaller in size.</br>Use this
            when you do not want to lose any of the original image's details. Works best for technical drawings,
            clip art and comics. </p>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row"><label for="thumbnails">Also include thumbnails:</label></th>
                        <td><input name="thumbnails" type="checkbox" id="thumbnails" <?= $checked ?>> Apply compression also to 
                                <strong><?=number_format(($quotaData['totalFiles'] - $quotaData['mainFiles']) - ($quotaData['totalProcessedFiles'] - $quotaData['mainProcessedFiles']))?> 
                                image thumbnails.</strong>
                            <p class="settings-info">Thumbnails count up to your total quota, and should be optimized for best results of your website's speed.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="backupImages">Image backup</label></th>
                        <td>
                            <input name="backupImages" type="checkbox" id="backupImages" <?= $checkedBackupImages ?>> Save and keep a backup of your original images in a separate folder.
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="backupImages">CMYK to RGB conversion</label></th>
                        <td>
                            <input name="cmyk2rgb" type="checkbox" id="cmyk2rgb" <?= $cmyk2rgb ?>>Adjust your images for computer and mobile screen display.
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="resize">Resize larger images</label></th>
                        <td>
                            <input name="resize" type="checkbox" id="resize" <?= $resize ?>> to maximum
                            <input type="text" name="width" id="width" style="width:70px" value="<?= max($this->ctrl->getResizeWidth(), min(1024, $minSizes['width'])) ?>" <?= $resizeDisabled ?>/> pixels wide &times; 
                            <input type="text" name="height" id="height" style="width:70px" value="<?= max($this->ctrl->getResizeHeight(), min(1024, $minSizes['height'])) ?>" <?= $resizeDisabled ?>/> pixels high
                            <p class="settings-info"> Recommended for large photos, like the ones taken with your phone. Saved space can go up to 80% after resizing.<br/>
                                The new resolution should not be less than your largest thumbnail size, which is <?=$minSizes['width']?> &times; <?=$minSizes['height']?> pixels.</p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="submit">
                <input type="submit" name="save" id="save" class="button button-primary" title="Save Changes" value="Save Changes"> &nbsp;and then&nbsp;
                <a class="button button-primary" title="Process all the images in your Media Library" href="upload.php?page=wp-short-pixel-bulk">Bulk Process</a>
            </p>
        </form>
        <script>
            var rad = document.wp_shortpixel_options.compressionType;
            var prev = null;
            var minWidth  = Math.min(1024, <?=$minSizes['width']?>),
                minHeight = Math.min(1024, <?=$minSizes['height']?>);
            for(var i = 0; i < rad.length; i++) {
                rad[i].onclick = function() {

                    if(this !== prev) {
                        prev = this;
                    }
                    alert('This type of optimization will apply to new uploaded images.\\nImages that were already processed will not be re-optimized.');
                };
            }
            function enableResize(elm) {
                if(jQuery(elm).is(':checked')) {
                    jQuery("#width,#height").removeAttr("disabled");
                } else {
                    jQuery("#width,#height").attr("disabled", "disabled");
                }
            }
            enableResize("#resize");
            jQuery("#resize").change(function(){ enableResize(this) });
            jQuery("#width").blur(function(){
                jQuery(this).val(Math.max(minWidth, parseInt(jQuery(this).val())));
            });
            jQuery("#height").blur(function(){
                jQuery(this).val(Math.max(minHeight, parseInt(jQuery(this).val())));
            });
        </script>
        <?php } ?>
        <script>
            function validateKey(){
                jQuery('#valid').val('validate');
                jQuery('#wp_shortpixel_options').submit();
            }
            jQuery("#key").keypress(function(e) {
                if(e.which == 13) {
                    jQuery('#valid').val('validate');
                }
            });
        </script>
        <?php
    }
    
    function displaySettingsStats($averageCompression, $savedSpace, $savedBandwidth, 
                         $quotaData, $remainingImages, $totalCallsMade, $fileCount, $backupFolderSize) { ?>
        <a id="facts"></a>
        <h3>Your ShortPixel Stats</h3>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row"><label for="averagCompression">Average compression of your files:</label></th>
                    <td><?=$averageCompression?>%</td>
                </tr>
                <tr>
                    <th scope="row"><label for="savedSpace">Saved disk space by ShortPixel</label></th>
                    <td><?=$savedSpace?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="savedBandwidth">Bandwith* saved with ShortPixel:</label></th>
                    <td><?=$savedBandwidth?></td>
                </tr>
            </tbody>
        </table>

        <p style="padding-top: 0px; color: #818181;" >* Saved bandwidth is calculated at 10,000 impressions/image</p>

        <h3>Your ShortPixel Plan</h3>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row" bgcolor="#ffffff"><label for="apiQuota">Your ShortPixel plan</label></th>
                    <td bgcolor="#ffffff">
                        <?=number_format($quotaData['APICallsQuota'])?>/month, renews in <?=floor(30 + (strtotime($quotaData['APILastRenewalDate']) - time()) / 86400)?> days, on <?=date('M d, Y', strtotime($quotaData['APILastRenewalDate']. ' + 30 days'))?> ( <a href="https://shortpixel.com/login/<?=$this->ctrl->getApiKey();?>" target="_blank">Need More? See the options available</a> )<br/>
                        <a href="https://shortpixel.com/login/<?=$this->ctrl->getApiKey()?>/tell-a-friend" target="_blank">Join our friend referral system</a> to win more credits. For each user that joins, you receive +100 images credits/month.
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="usedQUota">One time credits:</label></th>
                    <td><?=  number_format($quotaData['APICallsQuotaOneTimeNumeric'])?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="usedQUota">Number of images processed this month:</label></th>
                    <td><?=$totalCallsMade?> (<a href="https://api.shortpixel.com/v2/report.php?key=<?=$this->ctrl->getApiKey()?>" target="_blank">see report</a>)</td>
                </tr>
                <tr>
                    <th scope="row"><label for="remainingImages">Remaining** images in your plan:  </label></th>
                    <td><?=$remainingImages?> images</td>
                </tr>
            </tbody>
        </table>

        <p style="padding-top: 0px; color: #818181;" >** Increase your image quota by <a href="https://shortpixel.com/login/<?=$this->ctrl->getApiKey()?>" target="_blank">upgrading</a> your ShortPixel plan.</p>

        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row"><label for="totalFiles">Total number of processed files:</label></th>
                    <td><?=$fileCount?></td>
                </tr>
                <?php if($this->ctrl->backupImages()) { ?>
                <tr>
                    <th scope="row"><label for="sizeBackup">Original images are stored in a backup folder. Your backup folder size is now:</label></th>
                    <td>
                        <form action="" method="POST">
                            <?=$backupFolderSize?>
                            <input type="submit"  style="margin-left: 15px; vertical-align: middle;" class="button button-secondary" name="emptyBackup" value="Empty backups"/>
                        </form>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table> <?php        
    }
}

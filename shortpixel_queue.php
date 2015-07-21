<?php

class ShortPixelQueue {
    
    private $ctrl;    
    private $startBulkId;
    private $stopBulkId;
    private $bulkCount;
    private $bulkSkippedCount;
    private $bulkAlreadyDoneCount;
    private $lastBulkStartTime;
    private $lastBulkSuccessTime;
    private $bulkRunningTime;
    
    //handling older
    public function ShortPixelQueue($controller) {
        $this->__construct($controller);
    }

    public function __construct($controller) {
        $this->ctrl = $controller;
    //init the option if needed
        if(!isset($_SESSION["wp-short-pixel-priorityQueue"])) {
            //take the priority list from the options (we persist there the priority IDs from the previous session)
            $prioQueueOpt = WPShortPixel::getOpt( 'wp-short-pixel-priorityQueue', array());//here we save the IDs for the files that need to be processed after an image upload for example
            $_SESSION["wp-short-pixel-priorityQueue"] = array();
            foreach($prioQueueOpt as $ID) {
                $meta = wp_get_attachment_metadata($ID);
                WPShortPixel::log("INIT: Item $ID from options has metadata: " .json_encode($meta));
                if(!isset($meta['ShortPixelImprovement'])) {
                    $this->push($ID);
                }
            }
            update_option('wp-short-pixel-priorityQueue', $_SESSION["wp-short-pixel-priorityQueue"]);
            WPShortPixel::log("INIT: Session queue not found, updated from Options with "
                             .json_encode($_SESSION["wp-short-pixel-priorityQueue"]));
        }
        
        $this->startBulkId = WPShortPixel::getOpt( 'wp-short-pixel-query-id-start', 0);//current query ID used for postmeta queries
        $this->stopBulkId = WPShortPixel::getOpt( 'wp-short-pixel-query-id-stop', 0);//min ID used for postmeta queries
        $this->bulkCount = WPShortPixel::getOpt( "wp-short-pixel-bulk-count", 0);
        $this->bulkSkippedCount = WPShortPixel::getOpt( "wp-short-pixel-bulk-skipped-count", 0);
        $this->bulkAlreadyDoneCount = WPShortPixel::getOpt( "wp-short-pixel-bulk-done-count", 0);
        $this->lastBulkStartTime = WPShortPixel::getOpt( 'wp-short-pixel-last-bulk-start-time', 0);//time of the last start of the bulk. 
        $this->lastBulkSuccessTime = WPShortPixel::getOpt( 'wp-short-pixel-last-bulk-success-time', 0);//time of the last start of the bulk. 
        $this->bulkRunningTime = WPShortPixel::getOpt( 'wp-short-pixel-bulk-running-time', 0);//how long the bulk ran that far. 
    }
    
    public function get() {
        return $_SESSION["wp-short-pixel-priorityQueue"];//get_option("wp-short-pixel-priorityQueue");
    }
    
    public function push($ID)//add an ID to priority queue
    {
        $priorityQueue = $_SESSION["wp-short-pixel-priorityQueue"]; //get_option("wp-short-pixel-priorityQueue");
        WPShortPixel::log("PUSH: Push ID $ID into queue ".json_encode($priorityQueue));
        array_push($priorityQueue, $ID);
        $prioQ = array_unique($priorityQueue);
        $_SESSION["wp-short-pixel-priorityQueue"] = $prioQ;
        //push also to the options queue, in case the session gets killed retrieve frm there
        update_option('wp-short-pixel-priorityQueue', $prioQ);

        WPShortPixel::log("PUSH: Updated: ".json_encode($_SESSION["wp-short-pixel-priorityQueue"]));//get_option("wp-short-pixel-priorityQueue")));
    }

    public function getFirst($count = 1)//return the first values added to priority queue
    {
        $priorityQueue = $_SESSION["wp-short-pixel-priorityQueue"];//self::getOpt("wp-short-pixel-priorityQueue", array());
        $count = min(count($priorityQueue), $count);
        return(array_slice($priorityQueue, count($priorityQueue) - $count, $count));
    }

    public function remove($ID)//remove an ID from priority queue
    {
        $priorityQueue = $_SESSION["wp-short-pixel-priorityQueue"];//get_option("wp-short-pixel-priorityQueue");
        WPShortPixel::log("REM: Remove ID $ID from queue ".json_encode($priorityQueue));
        $newPriorityQueue = array();
        $found = false;
        foreach($priorityQueue as $item) {
            if($item != $ID) {
                $newPriorityQueue[] = $item;
            } else {
                $found = true;
            }
        }
        //update_option("wp-short-pixel-priorityQueue", $newPriorityQueue);
        $_SESSION["wp-short-pixel-priorityQueue"] = $newPriorityQueue;
        WPShortPixel::log("REM: " . ($found ? "Updated: " : "Not found") . json_encode($_SESSION["wp-short-pixel-priorityQueue"]));//get_option("wp-short-pixel-priorityQueue")));
        return $found;
    }
    
    public function bulkRunning() {
        //$bulkProcessingStatus = get_option('bulkProcessingStatus');
        return $this->startBulkId > $this->stopBulkId;
    }
    
    public function bulkPaused() {
        WPShortPixel::log("Bulk Paused: " . get_option( 'wp-short-pixel-cancel-pointer'));
        return WPShortPixel::getOpt( 'wp-short-pixel-cancel-pointer', 0);
    }
    
    public function bulkRan() {
        return WPShortPixel::getOpt("wp-short-pixel-bulk-ever-ran", 0) != 0;
    }
    
    public function  processing() {
        WPShortPixel::log("QUEUE: processing(): get:" . json_encode($this->get()));
        return $this->bulkRunning() || count($this->get());
    }
    
    public function getFlagBulkId() {
        return WPShortPixel::getOpt("wp-short-pixel-flag-id",0);
    }

    public function getStartBulkId() {
        return $this->startBulkId;
    }

    public function resetStartBulkId() {
        $this->setStartBulkId($this->ctrl->getMaxMediaId());
    }
    
    public function setStartBulkId($start){
        $this->startBulkId = $start;
        update_option("wp-short-pixel-query-id-start", $this->startBulkId);
    }

    public function getStopBulkId() {
        return $this->stopBulkId;
    }

    public function resetStopBulkId() {
        $this->stopBulkId = $this->ctrl->getMinMediaId();
        update_option("wp-short-pixel-query-id-stop", $this->stopBulkId);
    }
    
    public function resetBulkCount() {
        $res = $this->ctrl->countAllProcessableFiles($this->getFlagBulkId(), $this->stopBulkId);
        $this->bulkCount = $res["mainFiles"];
        update_option("wp-short-pixel-bulk-count", $this->bulkCount);
    }
    
    public function resetBulkAlreadyDoneCount() {
        $res = $this->ctrl->countAllProcessedFiles($this->getFlagBulkId(), $this->stopBulkId);
        $this->bulkAlreadyDoneCount =  $res["mainFiles"];
        update_option("wp-short-pixel-bulk-done-count", $this->bulkAlreadyDoneCount);
    }
    
    public function resetBulkSkippedCount() {
        $this->bulkSkippedCount =  0;
        update_option("wp-short-pixel-bulk-skipped-count", $this->bulkSkippedCount);
    }
    
    public function flagBulkStart() {
        update_option("wp-short-pixel-flag-id", $this->startBulkId);
        delete_option('bulkProcessingStatus');        
        add_option('bulkProcessingStatus', 'running');//set bulk flag        
    }
    
    public function startBulk() {
        $this->resetStartBulkId(); //start downwards from the biggest item ID            
        $this->resetStopBulkId();
        $this->flagBulkStart(); //we use this to detect new added files while bulk is running            
        $this->resetBulkCount(); //call this only after the resets above;
        $this->resetBulkAlreadyDoneCount();//call this only after the resets above;
        $this->resetBulkSkippedCount();
        update_option( 'wp-short-pixel-bulk-ever-ran', 1);
    }
    
    public function pauseBulk() {
        update_option( 'wp-short-pixel-cancel-pointer', $this->startBulkId);//we save this so we can resume bulk processing
        WPShortPixel::log("PAUSE: Pointer = ".get_option( 'wp-short-pixel-cancel-pointer'));
        $this->stopBulk();
    }
    
    public function stopBulk() {
        $this->startBulkId = $this->ctrl->getMaxMediaId();
        $this->stopBulkId = $this->startBulkId;
        update_option("wp-short-pixel-query-id-start", $this->startBulkId);
        update_option("wp-short-pixel-query-id-stop", $this->stopBulkId);
        delete_option('bulkProcessingStatus');
        $this->resetBulkSkippedCount();
        return WPShortPixel::getOpt('wp-short-pixel-bulk-ever-ran', 0);
    }
    
    public function resumeBulk() {
        $this->startBulkId = get_option( 'wp-short-pixel-cancel-pointer');
        update_option("wp-short-pixel-query-id-start", $this->startBulkId);//start downwards from the biggest item ID            
        $this->stopBulkId = $this->ctrl->getMinMediaId();
        update_option("wp-short-pixel-query-id-stop", $this->stopBulkId);
        //update_option("wp-short-pixel-flag-id", $this->startBulkId);//we use to detect new added files while bulk is running
        add_option('bulkProcessingStatus', 'running');//set bulk flag    
        $this->resetBulkSkippedCount();
        delete_option( 'wp-short-pixel-cancel-pointer');
        WPShortPixel::log("Resumed: (pause says: " . $this->bulkPaused() . ") Start from: " . $this->startBulkId . " to " . $this->stopBulkId);
    }
    
    public function addBulkSkipped($skipped) {
        $this->bulkSkippedCount += $skipped;
        update_option( "wp-short-pixel-bulk-skipped-count", $this->bulkSkippedCount);
    }
    
    public function markBulkComplete() {
        delete_option('bulkProcessingStatus');
        delete_option( 'wp-short-pixel-cancel-pointer');
    }
    
    public function resetBulk() {
        delete_option('bulkProcessingStatus');        
        delete_option( 'wp-short-pixel-cancel-pointer');
        $this->startBulkId = $this->stopBulkId = $this->ctrl->getMaxMediaId();
        update_option( 'wp-short-pixel-query-id-stop', $this->startBulkId );
        update_option( 'wp-short-pixel-query-id-start', $this->startBulkId );                    
        update_option('wp-short-pixel-bulk-running-time', 0);
        update_option('wp-short-pixel-last-bulk-start-time', 0);
        update_option('wp-short-pixel-last-bulk-success-time', 0);
    }
    
    public function logBulkProgress() {
        $t = time();
        if($t - $this->lastBulkSuccessTime > 120) { //if break longer than two minutes we mark a pause in the bulk
            $this->bulkRunningTime += ($this->lastBulkSuccessTime - $this->lastBulkStartTime);
            update_option('wp-short-pixel-bulk-running-time', $this->bulkRunningTime);
            $this->lastBulkStartTime = $this->lastBulkSuccessTime = $t;
            update_option('wp-short-pixel-last-bulk-start-time', $t);
            update_option('wp-short-pixel-last-bulk-success-time', $t);
        } else {
            $this->lastBulkSuccessTime = $t;
            update_option('wp-short-pixel-last-bulk-success-time', $t);
        }
    }
    
    public function getBulkPercent() {
        WPShortPixel::log("QUEUE.getBulkPercent: Flag:" . get_option("wp-short-pixel-flag-id"). " Start:" . $this->startBulkId . " Already done: " . $this->bulkAlreadyDoneCount . " Skipped: " . $this->bulkSkippedCount . " Bulk count:" . $this->bulkCount);
        WPShortPixel::log("                      calculated percent:" . round((get_option("wp-short-pixel-flag-id") - $this->startBulkId + $this->bulkAlreadyDoneCount - $this->bulkSkippedCount) / $this->bulkCount * 100));
        
        if($this->bulkCount == 0) return 100;
        // return maximum 99%
        return min(99, round((get_option("wp-short-pixel-flag-id") - $this->startBulkId + $this->bulkAlreadyDoneCount - $this->bulkSkippedCount) / $this->bulkCount * 100));
    }

    public function getDeltaBulkPercent() {
        WPShortPixel::log("QUEUE.getDeltaBulkPercent: Flag:" . get_option("wp-short-pixel-flag-id"). " Start:" . $this->startBulkId . " Already done: " . $this->bulkAlreadyDoneCount . " Bulk count:" . $this->bulkCount);
        return $this->getBulkPercent() - round($this->bulkAlreadyDoneCount / $this->bulkCount * 100);
    }
    
    public function getTimeRemaining (){
        $p = $this->getBulkPercent();
        $pAlready = round($this->bulkAlreadyDoneCount / $this->bulkCount * 100);
//        die("" . ($this->lastBulkSuccessTime - $this->lastBulkStartTime));
        if(($p - $pAlready) == 0) return 0;
        return round(((100 - $p) / ($p - $pAlready)) * ($this->bulkRunningTime + $this->lastBulkSuccessTime - $this->lastBulkStartTime)/60);
    }
}

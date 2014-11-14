<?php
/**********************************************************************
*  Author: Quentin Ligier (quentin.ligier at bluewin.ch)
*  Name..: ContentDeliver
*  Desc..: A content-delivery class
*
*/
class ContentDeliverException extends Exception {}

class ContentDeliver {


    // Compress CSS or JS
    public $compress = true;

    // Prefix CSS
    public $prefixes = true;

    // Remove comments
    public $remove_comments = true;

    // File type
    public $type = 'css';


    // Destination file
    protected $file;
    protected $fileMTime;

    // Sources files
    protected $sources;

    // User headers
    protected $userIfNoneMatch;
    protected $userIfModifiedSince;


    // Constructor
    public function __construct($destination, $sources) {
        $sources = (array)$sources;
        if (empty($sources))
            throw new ContentDeliverException('The source files can\'t be empty.');
        $this->file = (string)$destination;
        $this->sources = $sources;

        $this->fetchUserHeaders();
    }

    public function serveFile() {
        if (!$this->destFileIsValid()) {
            $this->processFile();
        }
        else
            $this->fileMTime = filemtime($this->file);

        if ($this->userFileIsValid())
            $this->sendNotModifiedHeader();
        else {
            if ('css' === $this->type)
                return $this->serveCSSFile();
            elseif ('js' === $this->type)
                return $this->serveJSFile();
            else
                throw new ContentDeliverException('Invalid file type');
        }
    }

    public function isCSS() {
        $this->type = 'css';
    }

    public function isJS() {
        $this->type = 'js';
    }




    //   P R O T E C T E D
    protected function destFileExists($throwErrorIfNot = false) {
        if (is_file($this->file) && is_readable($this->file))
            return true;
        if (true === $throwErrorIfNot)
            throw new ContentDeliverException('The destination file doesn\'t exist. Call ContentDeliver->check_file() before.');
        return false;
    }

    protected function fetchUserHeaders() {
        $this->userIfNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ?
                (string)trim($_SERVER['HTTP_IF_NONE_MATCH']) :
                false;
        $this->userIfModifiedSince = (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) ?
                strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) :
                false;
    }

    protected function CRC32File($filename) {
        return crc32(file_get_contents($filename));
    }

    protected function destFileIsValid() {
        return false;
        if (!$this->destFileExists($this->file))
            return false;

        $this->fileMTime = filemtime($this->file);
        foreach ($this->sources AS $file) {
            if (file_exists($file) && filemtime($file) > $this->fileMTime)
                return false;
        }

        return true;
    }

    protected function userFileIsValid() {
        return $this->userFileEtagIsValid() || $this->userFileDateIsValid();
    }

    protected function userFileEtagIsValid() {
        return $this->userIfNoneMatch === $this->getFileEtag();
    }

    protected function userFileDateIsValid() {
        return (false !== $this->userIfModifiedSince) ? $this->userIfModifiedSince > $this->fileMTime : false;
    }

    protected function getFileEtag() {
        return '"BLEK-CDN-'.$this->CRC32File($this->file).'"';
    }

    protected function sendNotModifiedHeader() {
        header('Not Modified', true, 304);
        //echo '304';
    }

    protected function getFile() {
        return is_file($this->file) ? file_get_contents($this->file) : '';
    }

    protected function processFile() {
        if ('css' === $this->type)
            return $this->processCSSFile();
        elseif ('js' === $this->type)
            return $this->processJSFile();
        else
            throw new ContentDeliverException('Invalid file type');
    }

    protected function processCSSFile() {
        $expandedCSS = '';
        foreach ($this->sources AS $file) {
            if (is_file($file) && is_readable($file))
                $expandedCSS .= file_get_contents($file);
        }

        file_put_contents($this->file.'.tmp', $expandedCSS);
        exec('java -jar /usr/share/yui-compressor/yui-compressor.jar --charset utf8 --line-break 3000 --type css '.$this->file.'.tmp -o '.$this->file);
        unlink($this->file.'.tmp');
    }

    protected function processJSFile() {
        $expandedJS = '';
        foreach ($this->sources AS $file) {
            if (is_file($file) && is_readable($file))
                $expandedJS .= file_get_contents($file);
        }

        file_put_contents($this->file.'.tmp', $expandedJS);
        exec('java -jar /usr/share/yui-compressor/yui-compressor.jar --charset utf8 --line-break 3000 --type js '.$this->file.'.tmp -o '.$this->file);
        unlink($this->file.'.tmp');
    }

    protected function serveCSSFile() {
        header('Content-type: text/css');
        header('ETag: '.$this->getFileEtag());
        header('Last-Modified: '.gmdate('D, d M Y H:i:s \G\M\T', $this->fileMTime));

        ob_start();
        readfile($this->file);
        ob_end_flush();
    }

    protected function serveJSFile() {
        header('Content-type: application/javascript');
        header('ETag: '.$this->getFileEtag());
        header('Last-Modified: '.gmdate('D, d M Y H:i:s \G\M\T', $this->fileMTime));

        ob_start('ob_gzhandler');
        readfile($this->file);
        ob_end_flush();
    }
}
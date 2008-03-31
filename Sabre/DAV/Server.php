<?php

    require_once 'Sabre/DAV/Lock.php';

    /**
     * Main DAV server class
     * 
     * @package Sabre
     * @subpackage DAV
     * @version $Id: Server.php 7 2008-01-02 05:47:17Z evertpot $
     * @copyright Copyright (C) 2007, 2008 Rooftop Solutions. All rights reserved.
     * @author Evert Pot (http://www.rooftopsolutions.nl/) 
     * @license license http://www.freebsd.org/copyright/license.html  BSD License (4 Clause)
     */
    class Sabre_DAV_Server {

        /**
         * Inifinity is used for some request supporting the HTTP Depth header and indicates that the operation should traverse the entire tree
         */
        const DEPTH_INFINITY = -1;

        /**
         * Nodes that are files, should have this as the type property
         */
        const NODE_FILE = 1;

        /**
         * Nodes that are directories, should use this value as the type property
         */
        const NODE_DIRECTORY = 2;

        /**
         * The tree object
         * 
         * @var Sabre_DAV_Tree 
         */
        protected $tree;

        /**
         * The base uri 
         * `
         * @var string 
         */
        protected $baseUri;

        /**
         * Class constructor 
         * 
         * @param Sabre_DAV_Tree $tree The tree object 
         * @return void
         */
        public function __construct(Sabre_DAV_Tree $tree) {

            $this->tree = $tree;

        }

        /**
         * Starts the DAV Server 
         *
         * @return void
         */
        public function exec() {

            try {

                $this->invoke();

            } catch (Sabre_DAV_Exception $e) {

                $this->sendHTTPStatus($e->getHTTPCode());
                throw $e;

            } catch (Exception $e) {

                $this->sendHTTPStatus(500);
                throw $e;

            }

        }

        /**
         * Sets the base responding uri
         * 
         * @param string $uri
         * @return void
         */
        public function setBaseUri($uri) {

            $this->baseUri = $uri;    

        }

        // {{{ HTTP Method implementations
        
        /**
         * HTTP OPTIONS 
         * 
         * @return void
         */
        protected function httpOptions() {

            $this->addHeader('Allows',strtoupper(implode(' ',$this->getAllowedMethods())));
            if ($this->tree->supportsLocks()) {
                $this->addHeader('DAV','1,2');
            } else {
                $this->addHeader('DAV','1');
            }
            $this->addHeader('MS-Author-Via','DAV');

        }

        /**
         * HTTP GET
         *
         * This method simply fetches the contents of a uri, like normal
         * 
         * @return void
         */
        protected function httpGet() {

            $nodeInfo = $this->tree->getNodeInfo($this->getRequestUri(),0);

            if ($nodeInfo[0]['size']) $this->addHeader('Content-Length',$nodeInfo[0]['size']);

            $this->addHeader('Content-Type', 'application/octet-stream');
            echo $this->tree->get($this->getRequestUri());

        }

        /**
         * HTTP HEAD
         *
         * This method is normally used to take a peak at a url, and only get the HTTP response headers, without the body
         * This is used by clients to determine if a remote file was changed, so they can use a local cached version, instead of downloading it again
         *
         * @todo currently not implemented
         * @return void
         */
        protected function httpHead() {

            throw new Sabre_DAV_MethodNotImplementedException('Head is not yet implemented');

        }

        /**
         * HTTP Delete 
         *
         * The HTTP delete method, deletes a given uri
         *
         * @return void
         */
        protected function httpDelete() {

            $this->tree->delete($this->getRequestUri());
            $this->sendHTTPStatus(204);

        }


        /**
         * WEBDAV PROPFIND 
         *
         * This WebDAV method requests information about an uri resource, or a list of resources
         * If a client wants to receive the properties for a single resource it will add an HTTP Depth: header with a 0 value
         * If the value is 1, it means that it also expects a list of sub-resources (e.g.: files in a directory)
         *
         * The request body contains an XML data structure that has a list of properties the client understands 
         * The response body is also an xml document, containing information about every uri resource and the requested properties
         *
         * It has to return a HTTP 207 Multi-status status code
         *
         * @todo currently this method doesn't do anything with the request-body, and just returns a default set of properties 
         * @return void
         */
        protected function httpPropfind() {

            // $xml = new Sabre_DAV_XMLReader(file_get_contents('php://input'));
            // $properties = $xml->parsePropfindRequest();
          
            $depth = $this->getHTTPDepth(1);
            // The only two options for the depth of a propfind is 0 or 1 
            if ($depth!=0) $depth = 1;

            // The requested path
            $path = $this->getRequestUri();

            $fileList = $this->tree->getNodeInfo($path,$depth);

            // This is a multi-status response
            $this->sendHTTPStatus(207);
            $data = $this->generatePropfindResponse($fileList);
            echo $data;

        }
        
        /**
         * HTTP PUT method 
         * 
         * This HTTP method updates a file, or creates a new one.
         *
         * If a new resource was created, a 201 Created status code should be returned. If an existing resource is updated, it's a 200 Ok
         *
         * @return void
         */
        protected function httpPut() {

            // First we'll do a check to see if the resource already exists
            try {
                $info = $this->tree->getNodeInfo($this->getRequestUri(),0); 
                
                // We got this far, this means the node already exists.
                // This also means we should check for the If-None-Match header
                if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH']) {

                    throw new Sabre_DAV_PrecondtionFailedException('The resource already exists, and an If-None-Match header was supplied');

                }
                
                // If the node is a collection, we'll deny it
                if ($info[0]['type'] == self::NODE_DIRECTORY) throw new Sabre_DAV_ConflictException('PUTs on directories are not allowed'); 

                $this->tree->put($this->getRequestUri(),$this->getRequestBody());
                $this->sendHTTPStatus(200);

            } catch (Sabre_DAV_FileNotFoundException $e) {

                // This means the resource doesn't exist yet, and we're creating a new one
                $this->tree->createFile($this->getRequestUri(),$this->getRequestBody());
                $this->sendHTTPStatus(201);

            }

        }

        /**
         * HTTP POST method
         *
         * This a WebDAV extension. This WebDAV server supports HTTP POST file uploads, coming from for example a browser.
         * It works the exact same as a PUT, only accepts 1 file and can either create a new file, or update an existing one
         *
         * If a post variable 'redirectUrl' is supplied, it will return a 'Location: ' header, thus redirecting the client to the given location
         */
        protected function httpPOST() {

            foreach($_FILES as $file) {

                $this->tree->put($this->getRequestUri().file_get_contents($file['tmp_name']));
                break;

            }

            // We assume > 5.1.2, which has the header injection attack prevention
            if (isset($_POST['redirectUrl']) && is_string($_POST['redirectUrl'])) header('Location: ' . $_POST['redirectUrl']);

        }


        /**
         * WebDAV MKCOL
         *
         * The MKCOL method is used to create a new collection (directory) on the server
         *
         * @return void
         */
        protected function httpMkcol() {

            $requestUri = $this->getRequestUri();

            // If there's a body, we're supposed to send an HTTP 415 Unsupported Media Type exception
            $requestBody = $this->getRequestBody();
            if ($requestBody) throw new Sabre_DAV_UnsupportedMediaTypeException();

            // We'll check if the parent exists, and if it's a collection. If this is not the case, we need to throw a conflict exception
            
            try {
                if ($nodeInfo = $this->tree->getNodeInfo(dirname($requestUri),0)) {
                    if ($nodeInfo['type']==self::NODE_FILE) {
                        throw new Sabre_DAV_ConflictException('Parent node is not a directory');
                    }
                }
            } catch (Sabre_DAV_FileNotFoundException $e) {

                // This means the parent node doesn't exist, and we need to throw a 409 Conflict
                throw new Sabre_DAV_ConflictException('Parent node does not exist');

            }

            $this->tree->createDirectory($this->getRequestUri());

        }

        /**
         * WebDAV HTTP MOVE method
         *
         * This method moves one uri to a different uri. A lot of the actual request processing is done in getCopyMoveInfo
         * 
         * @return void
         */
        protected function httpMove() {

            $moveInfo = $this->getCopyAndMoveInfo();

            $this->tree->move($moveInfo['source'],$moveInfo['destination']);

            // If a resource was overwritten we should send a 204, otherwise a 201
            $this->sendHTTPStatus($moveInfo['destinationExists']?204:201);

        }

        /**
         * WebDAV HTTP COPY method
         *
         * This method copies one uri to a different uri, and works much like the MOVE request
         * A lot of the actual request processing is done in getCopyMoveInfo
         * 
         * @return void
         */
        protected function httpCopy() {

            $copyInfo = $this->getCopyAndMoveInfo();

            $this->tree->copy($copyInfo['source'],$copyInfo['destination']);

            // If a resource was overwritten we should send a 204, otherwise a 201
            $this->sendHTTPStatus($copyInfo['destinationExists']?204:201);

        }

        /**
         * Locks an uri
         *
         * The WebDAV lock request can be operated to either create a new lock on a file, or to refresh an existing lock
         * If a new lock is created, a full XML body should be supplied, containing information about the lock such as the type 
         * of lock (shared or exclusive) and the owner of the lock
         *
         * If a lock is to be refreshed, no body should be supplied and there should be a valid If header containing the lock
         * 
         * @return void
         */
        protected function httpLock() {

            $uri = $this->getRequestUri();

            $lastLock = null;
            if (!$this->validateLock($uri,$lastLock)) {
                throw new Sabre_DAV_LockedException('You tried to lock an url that was already locked');
            }

            if ($body = $this->getRequestBody()) {
                $lockInfo = Sabre_DAV_Lock::parseLockRequest($body);
            } else {
                $lockInfo = new Sabre_DAV_Lock();
            }
            $lockInfo = Sabre_DAV_Lock::parseLockRequest($this->getRequestBody());

            if ($timeOut = $this->getTimeoutHeader) $lockInfo->timeOut = $timeOut;
            $lockInfo->timeOut = $this->getTimeoutHeader();

            if ($lastLock) $lockInfo->lockToken = $lastLock->lockToken;

            // If there was no locktoken, this means there was no request body, and also not an exiting locktoken in the header
            if (!$lockInfo->lockToken) throw new Sabre_DAV_BadRequestException('An xml body is required on lock requests');
            $this->tree->lockNode($uri,$lockInfo);

        }

        /**
         * Unlocks a uri
         *
         * This WebDAV method allows you to remove a lock from a node. The client should provide a valid locktoken through the Lock-token http header
         * The server should return 204 (No content) on success
         *
         * @return void
         */
        protected function httpUnlock() {

            $uri = $this->getRequestUri();
            
            $lockToken = isset($_SERVER['HTTP_LOCK_TOKEN'])?$_SERVER['HTTP_LOCK_TOKEN']:false;

            // If the locktoken header is not supplied, we need to throw a bad request exception
            if (!$lockToken) throw new Sabre_DAV_BadRequestException('No lock token was supplied');

            $locks = $this->tree->getLocks();

            foreach($locks as $lock) {

                if ($lock->lockToken == $lockToken) {

                    $this->tree->unlockNode($uri,$lock);
                    $this->sendHTTPStatus(204);
                    return;

                }

            }

            // If we got here, it means the locktoken was invalid
            throw new Sabre_DAV_PreconditionFailedException('The uri wasn\'t locked, or the supplied locktoken was incorrect');

        }

        // }}}
        // {{{ HTTP/WebDAV protocol helpers 

        /**
         * Returns a full HTTP status header based on a status code 
         * 
         * @param int $code 
         * @return string 
         */
        public function getHTTPStatus($code) {
            
            $msg = array(
                200 => 'Ok',
                201 => 'Created',
                204 => 'No Content',
                207 => 'Multi-Status',
                400 => 'Bad request',
                403 => 'Forbidden',
                404 => 'Not Found',
                405 => 'Method not allowed',
                409 => 'Conflict',
                412 => 'Precondition failed',
                415 => 'Unsupported Media Type',
                423 => 'Locked',
                500 => 'Internal Server Error',
                501 => 'Method not implemented',
                507 => 'Unsufficient Storage',
           ); 

            return 'HTTP/1.1 ' . $code . ' ' . $msg[$code];

        }

        /**
         * Sends an HTTP status header to the client 
         * 
         * @param int $code HTTP status code 
         * @return void
         */
        public function sendHTTPStatus($code) {

            header($this->getHTTPStatus($code));

        }

        /**
         * Handles a http request, and execute a method based on its name 
         * 
         * @return void
         */
        protected function invoke() {

            $method = strtolower($_SERVER['REQUEST_METHOD']);

            // Make sure this is a HTTP method we support
            if (in_array($method,$this->getAllowedMethods())) {

                call_user_func(array($this,'http' . $method));

            } else {

                // Unsupported method
                throw new Sabre_DAV_MethodNotImplementedException();

            }

        }

        /**
         * Returns an array with all the supported HTTP methods 
         * 
         * @return array 
         */
        protected function getAllowedMethods() {

            $methods = array('options','get','head','post','delete','trace','propfind','mkcol','put','proppatch','copy','move');
            if ($this->tree->supportsLocks()) array_push($methods,'lock','unlock');
            return $methods;

        }

        /**
         * Adds an HTTP response header 
         * 
         * @param string $name 
         * @param string $value 
         * @return void
         */
        protected function addHeader($name,$value) {

            header($name . ': ' . str_replace(array("\n","\r"),array('\n','\r'),$value));

        }

        /**
         * Gets the uri for the request, keeping the base uri into consideration 
         * 
         * @return string
         */
        public function getRequestUri() {

            return $this->calculateUri($_SERVER['REQUEST_URI']);

        }

        /**
         * Calculates the uri for a request, making sure that the base uri is stripped out 
         * 
         * @param string $uri 
         * @throws Sabre_DAV_PermissionDeniedException A permission denied exception is thrown whenever there was an attempt to supply a uri outside of the base uri
         * @return string
         */
        public function calculateUri($uri) {

            if ($uri[0]!='/' && strpos($uri,'://')) {

                $uri = parse_url($uri,PHP_URL_PATH);

            }

            if (strpos($uri,$this->baseUri)===0) {

                return trim(urldecode(substr($uri,strlen($this->baseUri))),'/');

            } else {

                throw new Sabre_DAV_PermissionDeniedException('Requested uri (' . $uri . ') is out of base uri (' . $this->baseUri . ')');

            }

        }

        /**
         * Returns the HTTP depth header
         *
         * This method returns the contents of the HTTP depth request header. If the depth header was 'infinity' it will return the Sabre_DAV_Server::DEPTH_INFINITY object
         * It is possible to supply a default depth value, which is used when the depth header has invalid content, or is completely non-existant
         * 
         * @param mixed $default 
         * @return int 
         */
        public function getHTTPDepth($default = self::DEPTH_INFINITY) {

            // If its not set, we'll grab the default
            $depth = isset($_SERVER['HTTP_DEPTH'])?$_SERVER['HTTP_DEPTH']:$default;

            // Infinity
            if ($depth == 'infinity') $depth = self::DEPTH_INFINITY;
            else {
                // If its an unknown value. we'll grab the default
                if ($depth!=="0" && (int)$depth==0) $depth == $default;
            }

            return $depth;

        }

        /**
         * Returns the entire HTTP request body 
         * 
         * @return string 
         */
        protected function getRequestBody() {

            return file_get_contents('php://input');

        }

        /**
         * validateLock should be called when a write operation is about to happen
         * It will check if the requested url is locked, and see if the correct lock tokens are passed 
         *
         * @param mixed $urls List of relevant urls. Can be an array, a string or nothing at all for the current request uri
         * @param mixed $lastLock This variable will be populated with the last checked lock object (Sabre_DAV_Lock)
         * @return bool
         */
        protected function validateLock($urls = null,&$lastLock = null) {

            if (is_null($urls)) {
                $urls = array($this->requestUri());
            } elseif (is_string($urls)) {
                $urls = array($urls);
            } elseif (!is_array($urls)) {
                throw new Sabre_DAV_Exception('The urls parameter should either be null, a string or an array');
            }

            $conditions = $this->getIfConditions();
            // We're going to loop through the urls and make sure all lock conditions are satisfied
            foreach($urls as $url) {

                $locks = $this->tree->getLockInfo($url);

                // If there were no conditions, but there were locks or the other way round, we fail 
                if ((!$conditions && $locks)||(!$locks && $conditions)) {
                    return false;
                }
              
                // If there were no locks or conditions, we go to the next url
                if (!$locks && !$conditions) continue;

                // See if there's a satisfied condition
                foreach($conditions as $condition) {

                    // If the condition has a url, and it doesn't match, check the next condition
                    if ($condition['url'] && $condition['url']!=$url) continue;

                    // Check the locks
                    foreach($locks as $lock) {
                        if ((!$condition['not'] && $lock->lockToken == $condition['token']) || ($condition['not'] && $lock->lockToken != $condition['token'])) {
                          
                            // If we have a matched lock, we'll populated the $lastLock variable
                            $lastLock = $lock;

                            // Condition satisfied, onto the next url
                            continue 2;

                        }

                    }

                }

                // No conditions satisfied, we fail
                return false;

            }

            // We got here, this means every condition was satisfied
            return true;

        }

        function getIfConditions() {

            $header = isset($_SERVER['HTTP_IF'])?$_SERVER['HTTP_IF']:'';
            if (!$header) return array();

            $matches = array();
            $regex = '/(?:\<(?P<url>.*?)\>\s)?\((?P<not>Not\s)?\<(?P<token>.*?)\>\)/im';
            preg_match_all($regex,$header,$matches,PREG_SET_ORDER);

            $conditions = array();

            foreach($matches as $match) {
                $condition = array(
                    'url'   => $match['url'],
                    'token' => $match['token'],
                    'not'   => $match['not'],
                );

                if (!$condition['url'] && count($conditions)) $condition['url'] = $conditions[count($conditions)-1]['url'];
                $conditions[] = $condition;
            }

        }

        
        /**
         * Returns information about Copy and Move requests
         * 
         * This function is created to help getting information about the source and the destination for the 
         * WebDAV MOVE and COPY HTTP request. It also validates a lot of information and throws proper exceptions 
         * 
         * The returned value is an array with the following keys:
         *   * source - Source path
         *   * destination - Destination path
         *   * destinationExists - Wether or not the destination is an existing url (and should therefore be overwritten)
         *
         * @return array 
         */
        function getCopyAndMoveInfo() {

            $source = $this->getRequestUri();

            // Collecting the relevant HTTP headers
            if (!isset($_SERVER['HTTP_DESTINATION'])) throw new Sabre_DAV_BadRequestException('The destination header was not supplied');
            $destination = $this->calculateUri($_SERVER['HTTP_DESTINATION']);
            $overwrite = isset($_SERVER['HTTP_OVERWRITE'])?$_SERVER['HTTP_OVERWRITE']:'T';

            if (strtoupper($overwrite)=='T') $overwrite = true;
            elseif (strtoupper($overwrite)=='F') $overwrite = false;

            // We need to throw a bad request exception, if the header was invalid
            else throw new Sabre_DAV_BadRequestException('The HTTP Overwrite header should be either T or F');

            // Collection information on relevant existing nodes
            $sourceInfo = $this->tree->getNodeInfo($source);

            try {
                $destinationParentInfo = $this->tree->getNodeInfo(dirname($destination));
                if ($destinationParentInfo[0]['type'] == self::NODE_FILE) throw new Sabre_DAV_UnsupportedMediaTypeException('The destination node is not a collection');
            } catch (Sabre_DAV_FileNotFoundException $e) {

                // If the destination parent node is not found, we throw a 409
                throw new Sabre_DAV_ConflictException('The destination node is not found');

            }

            try {

                $destinationInfo[0] = $this->tree->getNodeInfo($destination);
                
                // If this succeeded, it means the destination already exists
                // we'll need to throw precondition failed in case overwrite is false
                if (!$overwrite) throw new Sabre_DAV_PreconditionFailedException('The destination node already exists, and the overwrite header is set to false');

            } catch (Sabre_DAV_FileNotFoundException $e) {

                // Destination didn't exist, we're all good
                $destinationInfo = false;

            }

            // These are the three relevant properties we need to return
            return array(
                'source'            => $source,
                'destination'       => $destination,
                'destinationExists' => $destinationInfo==true,
            );

        }


        // }}} 
        // {{{ XML Writers  
        
        
        /**
         * Generates a WebDAV propfind response body based on a list of nodes 
         * 
         * @param array $list 
         * @return string 
         */
        private function generatePropfindResponse($list) {

            $xw = new XMLWriter();
            $xw->openMemory();
            $xw->setIndent(true);
            $xw->startDocument('1.0','UTF-8');
            $xw->startElementNS('d','multistatus','DAV:');

            foreach($list as $entry) {

                $this->writeProperty($xw,$_SERVER['REQUEST_URI'],$entry);

            }

            $xw->endElement();
            return $xw->outputMemory();

        }

        /**
         * Generates the xml for a single item in a propfind response.
         *
         * This method is called by generatePropfindResponse
         * 
         * @param XMLWriter $xw 
         * @param string $baseurl 
         * @param array $data 
         * @return void
         */
        private function writeProperty(XMLWriter $xw,$baseurl,$data) {

            $xw->startElement('d:response');
            $xw->startElement('d:href');

            // Base url : /services/dav/mydirectory
            $url = rtrim(urldecode($baseurl),'/');

            // Adding the node in the directory
            if (isset($data['name']) && trim($data['name'],'/')) $url.= '/' . trim((isset($data['name'])?$data['name']:''),'/');

            $url = explode('/',$url);

            foreach($url as $k=>$item) $url[$k] = rawurlencode($item);

            $url = implode('/',$url);

            // Adding the protocol and hostname. We'll also append a slash if this is a collection
            $xw->text('http://' . $_SERVER['HTTP_HOST'] . $url . ($data['type']==self::NODE_DIRECTORY&&$url?'/':''));
            $xw->endElement(); //d:href

            $xw->startElement('d:propstat');
            $xw->startElement('d:prop');

            // Last modification property
            $xw->startElement('d:getlastmodified');
            $xw->writeAttribute('xmlns:b','urn:uuid:c2f41010-65b3-11d1-a29f-00aa00c14882/');
            $xw->writeAttribute('b:dt','dateTime.rfc1123');
            $modified = isset($data['modified'])?$data['modified']:time();
            if (!(int)$modified) $modified = strtotime($modified);
            $xw->text(date(DATE_RFC1123,$modified));
            $xw->endElement(); // d:getlastmodified

            // Content-length property
            $xw->startElement('d:getcontentlength');
            $xw->text(isset($data['size'])?(int)$data['size']:'0');
            $xw->endElement(); // d:getcontentlength
                   
            // Resource type property

            $xw->startElement('d:resourcetype');
            if (isset($data['type'])&&$data['type']==self::NODE_DIRECTORY) $xw->writeElement('d:collection','');
            $xw->endElement(); // d:resourcetype

            $xw->endElement(); // d:prop
           
            $xw->writeElement('d:status',$this->getHTTPStatus(200));
           
            $xw->endElement(); // :d:propstat

            $xw->endElement(); // d:response
        }

        // }}}

    }

?>
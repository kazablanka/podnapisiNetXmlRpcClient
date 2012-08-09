<?php
/**
 *Podnapisi.NET XML-RPC service client class. 
 * Performs search for subtitles and gets the download links.
 * If used in CLI downloading and extracting of subtitles possible.
 * 
 * @author kazablanka
 */
class PodnapisiNETXmlRpcClient  
{
    private $XMLRPC_ERROR_MESSAGES = array(
        '200' => 'Ok',
        '300' => 'InvalidCredentials',
        '301' => 'NoAuthorisation',
        '302' => 'InvalidSession',
        '400' => 'MovieNotFound',
        '401' => 'InvalidFormat',
        '402' => 'InvalidLanguage',
        '403' => 'InvalidHash',
        '404' => 'InvalidArchive'    
    );
    
    const XMLRPC_RESULT_OK      = '200';
    const SORT_BY_NAME          = 0;
    const SORT_BY_NUM_DOWNLOADS = 1;
    
    const LANG_ENGLISH = 2;
    const LANG_GERMAN  = 5;
    const LANG_ITALIAN = 9;
    
    
    private $url = 'http://ssp.podnapisi.net:8000/RPC2/';
    private $userAgent = 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/22.0.1207.1 Safari/537.1';
    
    private $user;
    private $password;
    private $isCli;
    
    public $language;
    public $downloadNumber;
    public $isEpisodeInFileName;
    public $sortSubtitlesBy;
    
    private $session;
    private $nonce;
    
    private $subtitleSpecs;
    private $subtitleDownloadLinks;
    private $subtitles;

    /**
     *
     * @param array $params Array with the needed params.
     */
    function __construct($params) 
    {
        $this->user = $params['user'];
        $this->password = $params['password'];
        
        $this->isCli = ($params['isCli'])
                ? $params['isCli']
                : false;
        
        $this->language = ($params['language'])
                ? $params['language']
                : self::LANG_ENGLISH;
        
        $this->downloadNumber = ($params['downloadNumber'])
                ? $params['downloadNumber']
                : 2;
        
        $this->isEpisodeInFileName = ($params['isEpisodeInFileName'])
                ? $this->isEpisodeInFileName
                : false;
        
        $this->sortSubtitlesBy = ($params['sortSubtitlesBy'])
                ? $params['sortSubtitlesBy']
                : self::SORT_BY_NAME;
        
    }

    /**
     *Executes XML RPC request. Requires xml_rpc plugin enabled.
     * Uses CURL.
     * 
     *@param string $method Method name.
     *@param mixed $params Method params. Can be a string or an array os strings.
     *@return array An array of key-value pairs.
     */
    public function sendXmlRpcRequest($method, $params)
    {
        $request = xmlrpc_encode_request($method, $params);
        $req = curl_init($this->url);

        //Using the cURL extension to send it off,  first creating a custom header block
        $headers = array();
        array_push($headers,'Content-Type: text/xml');
        array_push($headers,'Content-Length: ' . strlen($request));
        array_push($headers,'\r\n');

        //URL to post to
        curl_setopt($req, CURLOPT_URL, $this->url);

        //Setting options for a secure SSL based xmlrpc server
        curl_setopt($req, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($req, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt( $req, CURLOPT_CUSTOMREQUEST, 'POST' );
        curl_setopt($req, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt($req, CURLOPT_HTTPHEADER, $headers );
        curl_setopt( $req, CURLOPT_POSTFIELDS, $request );

        //Finally run
        $response = curl_exec($req);

        //Close the cURL connection
        curl_close($req);
        
        return xmlrpc_decode($response);
    }
    
    private function printXmlrpcError($code)
    {
        $lineBreak = ($this->isCli) ? "\n" : "<br/>";
        echo 'Fatal error! ' . $this->XMLRPC_ERROR_MESSAGES[$code] . '.';
        echo $lineBreak;
    }
    
    private function printMessage($message)
    {
        $lineBreak = ($this->isCli) ? "\n" : "<br/>";
        echo $lineBreak;
        echo $message;
    }
    
    /**
     *Initiate the session.
     * @return boolean True if initiation successfull.
     */
    public function xmlRpcInitiate()
    {
        if ($session) {
            $this->printMessage('Already initiated.');
            return false;
        }
        
        $this->printMessage('Attempting initiantion...');
        $xmlrpcRequest = $this->sendXmlRpcRequest('initiate', $this->userAgent);
        if (!$xmlrpcRequest) {
            $this->printMessage('Error: No connection.');
            return false;
        }
        
        if ($xmlrpcRequest['status'] == self::XMLRPC_RESULT_OK){
            $this->session = $xmlrpcRequest['session'];
            $this->nonce = $xmlrpcRequest['nonce'];
            echo 'OK';
            return true;
        }
        else {
            $this->printXmlrpcError($xmlrpcRequest['status']);
            return false;
        }
    }
    
    /**
     *Authenticateto Podnapisi.NET service with the given credentials.
     * @return bool True if authenticated. 
     */
    public function xmlRpcAuthenticate()
    {
        $this->password = hash( 'sha256', md5($this->password) . $this->nonce );

        $this->printMessage('Attempting authentication...');

        if (!$this->user)
            $this->printMessage ('No user/pass specified. Authenticating anonymously. Download not possible.');
            
        $xmlrpcRequest = $this->sendXmlRpcRequest('authenticate', 
                array($this->session, $this->user, $this->password));
        
        if (!$xmlrpcRequest) {
            $this->printMessage('Error: No connection.');
            return false;
        }
        
        if ($xmlrpcRequest['status'] == self::XMLRPC_RESULT_OK) {
            echo 'OK';
            return true;
        } else {
            $this->printXmlrpcError($xmlrpcRequest['status']);
            return false;
        }
    }

    /**
     *Gets the download links for the given subtitle IDs. User must be authenticated.
     * Subtitle IDs may be obtained with the xmlGetSubtitleInfos() function.
     * This function should be executed after the xmlGetSubtitleInfos() function.
     * @return array Array of ID => downloadLink pairs.
     */
    public function xmlRpcGetDownloadLinks($subtitleKeys = null)
    {
        $baseurl = 'http://www.podnapisi.net/static/podnapisi/';
        
        //if no keys specified, try to get subtitle keys from local array,
        //which is filled with values during xmlGetSubtitleInfos() execution
        if (!$subtitleKeys) 
            if (isset($this->subtitleSpecs))
                $subtitleKeys = array_keys($this->subtitleSpecs);
            else {
                $this->printMessage("Download error! No subtitles keys.");
                return false;
            }

            
        //execute xml-rpc request
        $xmlrpcRequest = $this->sendXmlRpcRequest('download', 
                array($this->session, $subtitleKeys));

        $this->printMessage('Getting the download links...');

        if ($xmlrpcRequest['status'] == self::XMLRPC_RESULT_OK) {

            foreach ($xmlrpcRequest['names'] as $value)
                $downloadLinks[$value['id']] = $baseurl . $value['filename'];

            $this->subtitleDownloadLinks = $downloadLinks;
            echo 'OK';
            return true;
        }
        else { 
            $this->printXmlrpcError($xmlrpcRequest['status']);
            return false;
        }        
    }

    /**
     *Initiate then authenticate.
     * @param string $user User name.
     * @param string $pass Password.
     * @return bool True if successful. 
     */
    public function xmlRpcInitAuth($user, $pass)
    {
        return ($this->xmlRpcInitiate() && $this->xmlRpcAuthenticate($user, $pass))
            ? true : false;
    }

    /**
     *Gets the list of subtitle ID and info for the given criteria.
     * @param string $title Show/movie name.
     * @param string $season Season number.
     * @param string $episode Episode number.
     * @return mixed Array of subtitle info if successfull, false otherwise. 
     */
    public function xmlGetSubtitleInfos($title, $season, $episode)
    {
        $url = "http://www.podnapisi.net/en/ppodnapisi/search?sK=$title&sTS=$season&sTE=$episode&sJ=$this->language&sXML=1";
        

        $this->printMessage('Title: ' . $title);
        if ($episode) {
            $this->printMessage('Season: ' . $season);
            $this->printMessage('Episode: ' . $episode);
        }        
        $this->printMessage('');
            
        //get the xml from podnapisi.net
        //file() returns an array            
        $this->printMessage("Connecting to Podnapisi.NET XML service...");
        $xml = file($url);
        
        if ($xml) {
            echo 'OK';
            $xml = implode('', $xml);
            $subtitleObjects = simplexml_load_string($xml);
            
            $this->printMessage((int)count($subtitleObjects->subtitle) . ' subtitles found.');
            if (!$subtitleObjects->subtitle)
                return false;

            foreach ($subtitleObjects->subtitle as $subtitle) {
                $subtitleInfo[(int)$subtitle->id]['release'] = trim($subtitle->release);
                $subtitleInfo[(int)$subtitle->id]['downloads'] = trim($subtitle->downloads);
                $subtitleInfo[(int)$subtitle->id]['title'] = trim($subtitle->title);
                $subtitleInfo[(int)$subtitle->id]['tvEpisode'] = trim($subtitle->tvEpisode);
                $subtitleInfo[(int)$subtitle->id]['tvSeason'] = trim($subtitle->tvSeason);
            }
            $this->subtitleSpecs = $subtitleInfo;
            return true;
        } 
        else {
            $this->printMessage('Fatal error! Cannot connect the XML service.');
            return false;
        }
    }
        
    /**
     *Gets the Subtitles structure along with subtitle download links.
     * @return mixed Array on success. 
     */
    public function getSubtitlesData()
    {
        $subtitles = array();
        if (!$this->subtitleSpecs){
            $this->printMessage('Error! No subtitle data.');
            return null;
        }
        
        if ($this->sortSubtitlesBy == self::SORT_BY_NUM_DOWNLOADS) {
            
            //array multisort remembers only string indexes
            foreach ($this->subtitleSpecs as $key => $row)
                $arrLinks[$key . ' '] = $row;

            //get the 'downloads' comlumn for sorting
            foreach ($this->subtitleSpecs as $key => $row)
                $downloads[$key] = $row['downloads'];

            array_multisort($downloads, SORT_DESC, $arrLinks);

            //trim the kays back
            foreach ($arrLinks as $key => $row)
                $subtitles[trim($key)] = $row;
        } else 
            $subtitles = $this->subtitleSpecs;
   
        //attach download links to the subtitles structure
        foreach ($subtitles as $key => $value) 
            $subtitles[$key]['filename'] = $this->subtitleDownloadLinks[$key];
        return $subtitles;
    }
    
    /**
     *Echoes HTML table with founded subtitles.
     * @param Array $subtitleData Result of the xmlGetSubtitleInfos() function. 
     */
    public function echoHtmlSubtitleAnchors($subtitleData)
    {
        echo '<table>';
        foreach ($subtitleData as $key => $value) {
            echo '<tr><td>';
            echo " <a href='" . $value['filename']. "'>" . $value['release'] . "</a>";
            echo '</td><td>';
            echo $value['downloads'] ;
            echo '</td></tr>';    
        }    
        echo '</table>';
    }

    /**
     *Downloads the given file to current directory.
     * @param string $url Path to file.
     * @param string $downloadedFileName A name to which file is written.
     * @return mixed Archive file name on success. 
     */
    public static function cliDownloadFile($url, $downloadedFileName)
    {
        $zipFile = file($url);
        if ($zipFile) {
//            $downloadedFileName = $downloadedFileName . '.zip';
            $fh = fopen($downloadedFileName, 'w') or die("Can't open file.");
            fwrite($fh, implode('', $zipFile));
            fclose($fh);    
            $this->printMessage($downloadedFileName);
            return $downloadedFileName;
        } else
            return false;
    }
    
    /**
     *Extracts the given file in the current directory.
     * @param string $archiveFile Archive file path and name.
     * @param bool $removeArchiveAfterExtract Removes the file if true.
     * @return mixed File name of the first file in the archive on success.
     */
    public static function cliZipExtract($archiveFile, $removeArchiveAfterExtract = false)
    {
        if (!$this->isCli)
            return false;
        
        $zip = new ZipArchive;
        $res = $zip->open($archiveFile);
        if ($res === TRUE) {
            $zip->extractTo(getcwd());
            echo ' ... OK';
            
            $extractedFileName =  $zip->getNameIndex(0);
            $zip->close();            

            if ($removeArchiveAfterExtract)
                unlink($archiveFile);
            
            return $extractedFileName;
            
        } else {
            echo ' ... ' . $res;
            return false;
        }
    }

    
    /**
     *Use only from command line. Attempts to download the number of $ammount 
     * subtitles found in $subtitleData. 
     * @param Array $subtitleData Result of the xmlGetSubtitleInfos() function. 
     */
    public function cliDownloadSubtitles($subtitleData)
    {
        if (!$this->isCli)
            return false;
        
        $i = 0; 
        $previousSubtitleRelease = '';
        
        $this->printMessage('');
        $this->printMessage('Getting the first ' . $this->downloadNumber . ' files...');
        $this->printMessage('');
        
        foreach ($subtitleData as $key => $subtitle)
            if ($subtitle['release'] != $previousSubtitleRelease) {

                $zipFile = $this->cliDownloadFile(
                        $subtitle['filename'], $subtitle['release'] . 'zip', true);
                if ($zipFile)
                    $extractedFileName = $this->cliZipExtract($zipFile, true);
                
                //ads an episode number to the extracted file name
                if ($this->isEpisodeInFileName && $extractedFileName) {
                    $newFile = str_pad($subtitle['tvEpisode'], 2, '0', STR_PAD_LEFT) 
                            . '.' . $subtitle['release'];
                    rename($extractedFileName, $newFile.'.srt');
                }
                
                if (++$i >= $this->downloadNumber)
                    break;
                
                $previousSubtitleRelease = $subtitle['release'];
        }            
    }
    
    /**
     *Performs all the necessery actions to get the subtitles based on the search criteria.
     * @param string $title
     * @param string $season
     * @param string $episode
     * @return array Subtitle data array.
     */
    public function getSubtitles($title, $season, $episode)
    {
        if ($this->xmlGetSubtitleInfos($title, $season, $episode))
            if ($this->xmlRpcInitiate() && $this->xmlRpcAuthenticate())
                if ($this->xmlRpcGetDownloadLinks()) 
                    return $this->getSubtitlesData(
                            PodnapisiNETXmlRpcClient::SORT_BY_NUM_DOWNLOADS);
    }
}










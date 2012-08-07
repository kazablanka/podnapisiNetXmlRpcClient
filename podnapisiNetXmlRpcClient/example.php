<?php
//Example 

include 'xmlrpc-podnapisi.php';

//Get the search parameters 
if (isset($argv[1])){
    $title =  $argv[1];
    $season = $argv[2];
    $episode= $argv[3];
    $isCli = true;
} else {
    $title = $_GET['title'];
    $season = $_GET['season'];
    $episode= $_GET['episode'];
}

if ($title)
{
    $podnapisiNetClient = new PodnapisiNETXmlRpcClient('user', 'pass', $isCli);

    $subtitleData = $podnapisiNetClient->getSubtitles($title, $season, $episode);


    if ($subtitleData)
        if ($isCli) 
            $podnapisiNetClient->cliDownloadSubtitles ($subtitleData, 2, true);
        else 
            $podnapisiNetClient->echoHtmlSubtitleAnchors ($subtitleData);
}       






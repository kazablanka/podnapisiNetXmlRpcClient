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
    $podnapisiNetClient = new PodnapisiNETXmlRpcClient(array(
        'user'                  => 'user',
        'password'              => 'pass',
        'isCli'                 => $isCli,
        'language'              => PodnapisiNETXmlRpcClient::LANG_ENGLISH,
        'downloadNumber'        => 2,
        'isEpisodeInFileName'   => true,
        'sortSubtitlesBy'       => PodnapisiNETXmlRpcClient::SORT_BY_NUM_DOWNLOADS
    ));

    $subtitleData = $podnapisiNetClient->getSubtitles($title, $season, $episode);


    if ($subtitleData)
        if ($isCli) 
            $podnapisiNetClient->cliDownloadSubtitles ($subtitleData, 2, true);
        else 
            $podnapisiNetClient->echoHtmlSubtitleAnchors ($subtitleData);
}       






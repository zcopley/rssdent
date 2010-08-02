<?php
//
//  rssdent.php
//
//  h@x0red By Zach Copley (zach.status.net)
//  Give this script an RSS feed to suck on, and it will spit the latest
//  items in the feed out as dents on your indenti.ca/StatusNet account.
//  Uses ur1.ca to shorten urls.  Requires PHP 5 and SimplePie 1.1.1.
//
require_once('simplepie.inc');

$NICKNAME = 'jed';
$PASSWORD = 'lizard';
$FEEDURL = 'http://wendolonia.com/blog/feed/';
$STATUSNET_BASE = 'http://statusnetdev.net/zach';
$CURRENT_NOTICE_URL = "$STATUSNET_BASE/api/users/show/$NICKNAME.json";
$STATUSNET_UPDATE_URL = "$STATUSNET_BASE/api/statuses/update.xml";

// Add some hashtags to items
$HASHTAGS = array(
    '(mars|martian)' => '#mars',
    'ghost' => '#ghosts',
    'chupa' => '#chupacabra',
    'bigfoot' => '#bigfoot',
    'chemtrail' => '#chemtrails',
    'robot' => '#robot'
    );

date_default_timezone_set('UTC'); # Make sure this is same TZ as your feed!

$current_notice_date = strtotime(get_current_notice_date($CURRENT_NOTICE_URL));

$feed = new SimplePie();
$feed->set_feed_url($FEEDURL);
$feed->enable_cache(false);
$feed->enable_order_by_date(true);
$feed->init();

$notices = array();

foreach ($feed->get_items() as $item) {
    $notice = process_item($item);
    if (empty($notice)) {
        echo "Couldn't send: " . $item->get_title() . "\n";
    } else {
        array_unshift($notices, $notice);
    }
}

if (!empty($notices)) {
    array_map("send_notice", $notices);
} else {
    print "No new items to send.\n";
}

exit();


function setup_curl() {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_USERAGENT, "rssdent");
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FAILONERROR, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    return $ch;
}

function get_current_notice_date($url) {

    $ch = setup_curl();
    curl_setopt($ch, CURLOPT_URL, $url);

    $buffer = curl_exec($ch);

    if (!$buffer) {
        printf("cURL error: %s\n", curl_error($ch));
        curl_close($ch);
        exit(1);
    }

    curl_close($ch);

    $notice = json_decode($buffer);

    if (!$notice->status->text) {
        print "no notices yet.\n";
        return;
    }

    print 'Current notice: ' . $notice->status->text . ' (' . $notice->status->created_at . ')' . "\n";
    return $notice->status->created_at;

}

function process_item($item) {

    global $current_notice_date, $HASHTAGS;

    $new_notice_date = strtotime($item->get_date());

    if ($new_notice_date > $current_notice_date) {
        $title = truncate($item->get_title());
        $link = ur1shorten($item->get_link());

        if (empty($link)) {
            echo "Couldn't get ur1.ca shorlink for " . $item->get_link() . "\n";
            return null;
        }

        $notice = "$title $link";
        $noticelen = strlen($notice);

        # Tack on some tags, if there's room
        foreach ($HASHTAGS as $keyword => $tag) {
            if (eregi($keyword, $notice)) {
                if (($noticelen + strlen($tag) + 1) <= 140 ) {
                    $notice = $notice . " $tag";
                }
            }
        }
        return $notice;
    }
    return null;
}

function send_notice($notice) {

    global $NICKNAME, $PASSWORD, $STATUSNET_UPDATE_URL;

    $ch = setup_curl();
    curl_setopt($ch, CURLOPT_URL, $STATUSNET_UPDATE_URL);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, array('status' => $notice, 'source' => 'rssdent'));
    curl_setopt($ch, CURLOPT_USERPWD, "$NICKNAME:$PASSWORD");
    $buffer = curl_exec($ch);

    if (empty($buffer)) {
        printf("send_notice - trouble posting to '%s', cURL error: %s\n",
            $STATUSNET_UPDATE_URL,
            curl_error($ch)
        );
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    print "Dent: $notice\n";

    sleep(1); # Just to be polite

    return;
}

function ur1shorten($url) {

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_USERAGENT, "ur1shorten");
    curl_setopt($ch, CURLOPT_URL,"http://ur1.ca");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "longurl=$url");
    curl_setopt($ch, CURLOPT_FAILONERROR, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $html = curl_exec($ch);

    if (!$html) {
        printf("url1shorten - cURL error: %s\n", curl_error($ch));
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $hrefs = $xpath->evaluate("/html/body/p[@class='success']/a");
    return empty($hrefs) ? null : $hrefs->item(0)->getAttribute('href');
}

function truncate($str) {

    if (strlen($str) > 100) {
        // truncate at 100 chars -- Hey, we have to leave some room for the link
        $str = substr($str, 0, 100) . '...';
    }

    return trim(htmlspecialchars_decode($str));
}

?>

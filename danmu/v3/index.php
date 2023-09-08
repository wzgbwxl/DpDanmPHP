<?php
header( "Access-Control-Allow-Origin:*" );
header( 'Access-Control-Allow-Headers:x-requested-with,content-type' );
$dir = 'json/';
if ( !is_dir( $dir ) ) {
    mkdir( $dir, 0777 );
    file_put_contents( $dir . 'index.html', '' );
}
$rws_post = file_get_contents( 'php://input' );
$body = json_decode( $rws_post );
if ( isset( $_GET[ 'id' ] ) ) {
    $danmakuFile = $dir . $_GET[ 'id' ] . '.json';
    @$danmakuContent = file_get_contents( $danmakuFile );
    if ( $danmakuContent ) {
        echo( $danmakuContent );
    } else {
        echo '{"code": 0,"data": []}';
    }
} elseif ( $body->id ) {
    $danmakuFile = $dir . $body->id . '.json';
    @$danmakuContent = file_get_contents( $danmakuFile );
    $data = array(
        array(
            $body->time,
            $body->type,
            $body->color,
            "$body->author",
            "$body->text"
        )
    );
    $danmaku = array(
        'code' => 0,
        'data' => $data
    );
    if ( !$danmakuContent ) {
        fopen( $danmakuFile, "w" );
        file_put_contents( $danmakuFile, json_encode( $danmaku ) );
        echo json_encode( $danmaku );
    } else {
        $newDanmaku = json_decode( $danmakuContent, true );
        array_push( $newDanmaku[ "data" ], $danmaku[ 'data' ][ 0 ] );
        file_put_contents( $danmakuFile, json_encode( $newDanmaku ) );
        echo json_encode( $danmaku );
    }
} else {
    echo '{"code": 0,"data": []}';
}
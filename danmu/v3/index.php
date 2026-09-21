<?php
/**
 * DPlayer 弹幕 API（单文件版）
 *
 * 弹幕按视频 id 存储为 json/{id}.json，无需数据库。
 * 接口：
 *   GET  ?id={id}                                 获取某视频的全部弹幕
 *   POST {id,time,type,color,author,text[,token]} 新增一条弹幕（JSON 请求体）
 *
 * 要求：PHP 5.4+
 */

// ---------------- 配置 ----------------
define( 'MAX_DANMAKU', 2000 );        // 每个视频最多保留的弹幕数，0 为不限
define( 'MAX_AUTHOR_LENGTH', 64 );    // 昵称最大长度
define( 'MAX_TEXT_LENGTH', 500 );     // 弹幕内容最大长度
define( 'WRITE_TOKEN', '' );          // 写入口令，留空则不校验（前端需配置 danmaku.token）
define( 'THROTTLE_SECONDS', 3 );      // 同一 IP 两次发送的最小间隔（秒），0 为不限

// ---------------- 基础响应头 ----------------
header( 'Access-Control-Allow-Origin: *' );
header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
header( 'Access-Control-Allow-Headers: Content-Type, X-Requested-With' );
header( 'Access-Control-Max-Age: 86400' );
header( 'Content-Type: application/json; charset=utf-8' );
header( 'X-Content-Type-Options: nosniff' );
header( 'Cache-Control: no-store' );

// 浏览器跨域预检请求
if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
    http_response_code( 204 );
    exit;
}

$dir = __DIR__ . '/json/';
if ( !is_dir( $dir ) ) {
    @mkdir( $dir, 0755, true );
    @file_put_contents( $dir . 'index.html', '' );
}

/** 输出统一 JSON 并结束 */
function respond( $code, $data, $message = '' ) {
    $out = array( 'code' => $code, 'data' => $data );
    if ( $message !== '' ) {
        $out['message'] = $message;
    }
    echo json_encode( $out );
    exit;
}

/** 校验视频 id，白名单限制，杜绝目录穿越 */
function valid_id( $id ) {
    return is_string( $id ) && preg_match( '/^[A-Za-z0-9._-]{1,64}$/', $id );
}

/** 清洗文本：转为合法 UTF-8、去除控制字符、限制长度 */
function clean_text( $str, $max ) {
    $str = (string)$str;
    if ( function_exists( 'iconv' ) ) {
        $str = iconv( 'UTF-8', 'UTF-8//IGNORE', $str );
        if ( $str === false ) {
            $str = '';
        }
    }
    $str = preg_replace( '/[\x00-\x1F\x7F]/', '', $str );
    $str = trim( $str );
    if ( function_exists( 'mb_substr' ) ) {
        return mb_substr( $str, 0, $max, 'UTF-8' );
    }
    return substr( $str, 0, $max ); // 无 mbstring 时按字节截断
}

/** 读取弹幕文件，返回 data 数组；文件缺失或损坏时返回空数组 */
function read_danmaku( $file ) {
    $content = @file_get_contents( $file );
    if ( $content === false ) {
        return array();
    }
    $data = json_decode( $content, true );
    if ( !is_array( $data ) || !isset( $data['data'] ) || !is_array( $data['data'] ) ) {
        return array();
    }
    return $data['data'];
}

/** 写入弹幕文件：加锁串行化读改写，临时文件 + 原子替换避免写坏 */
function write_danmaku( $file, $data ) {
    $json = json_encode( array( 'code' => 0, 'data' => $data ) );
    $lock = @fopen( $file . '.lock', 'c' );
    if ( $lock ) {
        @flock( $lock, LOCK_EX );
    }
    $tmp = $file . '.tmp';
    if ( file_put_contents( $tmp, $json ) !== false ) {
        @rename( $tmp, $file ); // 原子替换
    } else {
        @file_put_contents( $file, $json, LOCK_EX ); // 回退直接写
    }
    if ( $lock ) {
        @flock( $lock, LOCK_UN );
        @fclose( $lock );
    }
}

/** 简单频率限制：同一 IP 在 THROTTLE_SECONDS 内只能发送一次 */
function throttle_ok( $dir ) {
    if ( THROTTLE_SECONDS <= 0 ) {
        return true;
    }
    $ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    $file = $dir . '.throttle';
    $now  = time();
    $map  = json_decode( @file_get_contents( $file ), true );
    if ( !is_array( $map ) ) {
        $map = array();
    }
    if ( isset( $map[$ip] ) && $now - $map[$ip] < THROTTLE_SECONDS ) {
        return false;
    }
    $map[$ip] = $now;
    foreach ( $map as $k => $v ) { // 清理 1 小时前的记录，防止文件膨胀
        if ( $now - $v > 3600 ) {
            unset( $map[$k] );
        }
    }
    @file_put_contents( $file, json_encode( $map ), LOCK_EX );
    return true;
}

switch ( $_SERVER['REQUEST_METHOD'] ) {

    // ---------------- 获取弹幕 ----------------
    case 'GET':
        $id = isset( $_GET['id'] ) ? $_GET['id'] : '';
        if ( !valid_id( $id ) ) {
            respond( 1, array(), 'invalid id' );
        }
        respond( 0, read_danmaku( $dir . $id . '.json' ) );
        break;

    // ---------------- 发送弹幕 ----------------
    case 'POST':
        $body = json_decode( file_get_contents( 'php://input' ) );
        $id   = is_object( $body ) && isset( $body->id ) ? $body->id : '';
        if ( !valid_id( $id ) ) {
            respond( 1, array(), 'invalid id' );
        }
        if ( WRITE_TOKEN !== '' && ( !isset( $body->token ) || $body->token !== WRITE_TOKEN ) ) {
            respond( 1, array(), 'invalid token' );
        }
        if ( !throttle_ok( $dir ) ) {
            respond( 1, array(), 'too frequent' );
        }
        if ( !isset( $body->time ) || !is_numeric( $body->time ) || $body->time < 0
            || !isset( $body->type ) || !is_numeric( $body->type )
            || !isset( $body->color ) || !isset( $body->text ) ) {
            respond( 1, array(), 'invalid field' );
        }
        $time = (float)$body->time;
        $type = (int)$body->type;
        if ( $type < 0 || $type > 3 ) {
            respond( 1, array(), 'invalid type' );
        }
        $color = (int)$body->color;
        if ( is_string( $body->color ) && strlen( $body->color ) > 0 && $body->color[0] === '#' ) {
            $color = (int)hexdec( substr( $body->color, 1 ) );
        }
        if ( $color < 0 || $color > 16777215 ) {
            respond( 1, array(), 'invalid color' );
        }
        $author = clean_text( isset( $body->author ) ? $body->author : '', MAX_AUTHOR_LENGTH );
        $text   = clean_text( $body->text, MAX_TEXT_LENGTH );
        if ( $text === '' ) {
            respond( 1, array(), 'empty text' );
        }

        $file    = $dir . $id . '.json';
        $danmaku = read_danmaku( $file );
        $danmaku[] = array( $time, $type, $color, $author, $text );
        if ( MAX_DANMAKU > 0 && count( $danmaku ) > MAX_DANMAKU ) {
            $danmaku = array_slice( $danmaku, count( $danmaku ) - MAX_DANMAKU );
        }
        write_danmaku( $file, $danmaku );
        respond( 0, array( array( $time, $type, $color, $author, $text ) ) );
        break;

    // ---------------- 其他方法 ----------------
    default:
        http_response_code( 405 );
        respond( 1, array(), 'method not allowed' );
}

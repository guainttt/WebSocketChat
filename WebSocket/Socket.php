<?php
/**
 * Created by PhpStorm.
 * User: SUN
 * Date: 2021/3/13
 * Time: 0:29
 */
namespace WebSocket;
class Socket
{
    //服务端
    protected $master = null;
    //socket连接池
    protected $connectPool = [];
    //握手池 http升级websocket
    protected $handPool = [];
    
    public function __construct($ip,$port)
    {
        $this->startServer($ip,$port);
    }
    public function startServer($ip,$port)
    {
        $this->connectPool[] = $this->master= \socket_create(AF_INET,SOCK_STREAM,SOL_TCP);
        \socket_bind($this->master,$ip,$port);
        //1000 并发数
        \socket_listen($this->master,1000);
        while (true){
            $sockets = $this->connectPool;
            $write = null;
            $except = null;//排除
            //60秒 超时时间
            \socket_select($sockets,$write,$except,60);
            
            //从socket里拿数据
            foreach ($sockets as $socket){
                //服务端
                if ($socket == $this->master){
                    //服务端 socket可读说明有新用户连接
                    $this->connectPool[] = $client = \socket_accept($this->master);
                    //                     var_dump($client);exit;
                    $keyArr = \array_keys($this->connectPool,$client);
                    $key = end($keyArr);
                    $this->handPool[$key] = false;
                    
                }else{
                    //                    客户端
                    $length = \socket_recv($socket,$buffer,1024,0);
                    if ($length<1){
                        //                        客户端已经断开连接了
                        //                        断开服务端连接
                        $this->close($socket);
                    }else{
                        //                        数据是正常长度
                        $key = \array_search($socket,$this->connectPool);
                        //                        还没有握手
                        if ($this->handPool[$key] == false){
                            //                            握手
                            $this->handShake($socket,$buffer,$key);
                        }else{
                            //                               解帧和封帧
                            $message = $this->deFrame($buffer);
                            $message = $this->enFrame($message);
                            //发送给所有人
                            $this->send($message);
                            
                        }
                        
                    }
                    
                    
                }
            }
        }
    }
    
    /**
     * 客户端断开连接
     * @param $socket
     */
    public function close($socket)
    {
        $key = \array_search($socket,$this->connectPool);
        unset($this->connectPool[$key]);
        unset($this->handPool[$key]);
        \socket_close($socket);
    }
    
    /**
     * http升级websocket
     * @param $socket
     * @param $buffer
     * @param $key
     */
    public function handShake($socket,$buffer,$key)
    {
        if(preg_match("/Sec-WebSocket-Key:\s*(.*?)\r\n/", $buffer, $match)){
            $responseKey = base64_encode(sha1($match[1] .'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true));
            //按照协议组合信息进行返回
            //        加载头
            $resposeHeader = "HTTP/1.1 101 Switching Protocols\r\n";
            $resposeHeader .= "Upgrade: websocket\r\n";
            $resposeHeader .= "Sec-WebSocket-Version: 13\r\n";
            $resposeHeader .= "Connection: Upgrade\r\n";
            $resposeHeader .= "Sec-WebSocket-Accept: " . $responseKey . "\r\n\r\n";
            //        发动送到客户端
            socket_write($socket,$resposeHeader,strlen($resposeHeader));
            //对已经握手的client做标志
            $this->handPool[$key]=true;
        }
    }
    
    //    http升级websocket
    /*    public function handShake2($socket,$buffer,$key)
        {
            //        提取websocket传的key并经行加密，
            //        这是固定的握手机制获取ser-websocket-key：里面的key
            $buffer = substr($buffer,strpos($buffer,'Sec-WebSocket-Key:')+18);
            $secKey = trim(substr($buffer,0,strpos($buffer,"\r\n")));
            //    加密
            $resKey = base64_encode(sha1($key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11",true));
            
            //按照协议组合信息进行返回
            //        加载头
            $resposeHeader = "HTTP/1.1 101 Switching Protocols\r\n";
            $resposeHeader .= "Upgrade: websocket\r\n";
            $resposeHeader .= "Sec-WebSocket-Version: 13\r\n";
            $resposeHeader .= "Connection: Upgrade\r\n";
            $resposeHeader .= "Sec-WebSocket-Accept: " . $resKey . "\r\n\r\n";
            //        发动送到客户端
            socket_write($socket,$resposeHeader,strlen($resposeHeader));
            //对已经握手的client做标志
            $this->handPool[$key]=true;
        }*/
    /**
     * 数据解帧
     */
    public function deFrame($buffer)
    {
        $len = $masks = $data = $decoded = null;
        $len = ord($buffer[1]) & 127;
        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        }
        else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        }
        else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        return $decoded;
    }
    /**
     * 封帧
     */
    public function enFrame($message)
    {
        $len = strlen($message);
        if ($len <= 125) {
            return "\x81" . chr($len) . $message;
        } else if ($len <= 65535) {
            return "\x81" . chr(126) . pack("n", $len) . $message;
        } else {
            return "\x81" . char(127) . pack("xxxxN", $len) . $message;
        }
    }
    /**
     * 群聊发送给所有客户端
     */
    public function send($message)
    {
        foreach ($this->connectPool as $socket){
            if ($socket != $this->master){
                socket_write($socket,$message,strlen($message));
            }
        }
    }
    
    
    
}
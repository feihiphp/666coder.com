<?php





//最近消息列表。
$table = new swoole_table(1024);
$table->column('content', swoole_table::TYPE_STRING,1000);
$table->create();

//用户列表
$table2 = new swoole_table(1024);
$table2->column('fd', swoole_table::TYPE_INT,1000);
$table2->column('uname', swoole_table::TYPE_STRING,1000);
$table2->create();



//创建websocket服务器对象，监听0.0.0.0:9502端口
$ws = new swoole_websocket_server("0.0.0.0", 9502);
//监听WebSocket连接打开事件
$ws->on('open', function ($ws, $request) {
    global  $table;

    foreach($table as $row)
    {
        $data = $row['content'];

        echo $data."\n";


        $ws->push($request->fd, $data);
    }
    $ws->push($request->fd, '{"from":"系统","type":"system","content":"欢迎进入北岸聊天室,随便灌水!"}');
});
//监听WebSocket消息事件
$ws->on('message', function ($ws, $frame) {
    global  $table;
    global  $table2;
    $message = json_decode($frame->data,true);

    //过滤下html标签。再组合回去。
    $message['from'] = htmlspecialchars($message['from']);
    $message['type'] = htmlspecialchars($message['type']);
    $message['content'] = htmlspecialchars($message['content']);

    $frame->data = json_encode($message);



    //过滤输入。from  type  content 3个内容。不符合的无需响应。
    $guestexp = '\xA1\xA1|\xAC\xA3|^Guest|^\xD3\xCE\xBF\xCD|\xB9\x43\xAB\xC8';
    $len = strlen($message['from']);
    if($len > 15 || $len < 3 || preg_match("/\s+|^c:\\con\\con|[%,\*\"\s\<\>\&]|$guestexp/is", $message['from'])) {
        return FALSE;
    }

    $len = strlen($message['type']);
    if($len > 15 || $len < 3 || preg_match("/\s+|^c:\\con\\con|[%,\*\"\s\<\>\&]|$guestexp/is", $message['type'])) {
        return FALSE;
    }

    $len = strlen($message['content']);
    if($len > 255 || $len < 3 || preg_match("/\s+|^c:\\con\\con|[%,\*\"\s\<\>\&]|$guestexp/is", $message['content'])) {
        return FALSE;
    }









    //记录登录的信息
    if ($message['type'] == 'login'){
        $table2->set($frame->fd,['uname'=>$message['from']]);
        //需要发送用户列表给前端。
        $user_list = [];
        foreach ($table2 as $row){
            $user_list[] = $row['uname'];
        }
        $message['user_list'] = $user_list;
        $frame->data = json_encode($message);

    }else{
        //记录非登录的消息
        $k = count($table);
        if (count($table) >10){
            //需要挨个移动位置。
            for ($i=1;$i<=10;$i++){
                if($table->exist($i+1)){
                    $tmp = $table->get($i+1); // 获取指定行。
                    $table->set($i,['content'=>$tmp['content']]);
                }else{
                    $table->set($i,['content'=>"{$frame->data}"]);
                }
            }
        }else{
            //没有超过10条则直接保存。
            $table->set($k,['content'=>"{$frame->data}"]);
        }
    }
    //需要遍历。发送给其他人。
    foreach ($ws->connections as $fd){
        $ws->push($fd, "{$frame->data}");
    }
});
//监听WebSocket连接关闭事件
$ws->on('close', function ($ws, $fd) {
    echo "client-{$fd} is closed\n";
    global $table2;
    $uname = $table2->get($fd)['uname'];
    $table2->del($fd);
    //需要发送用户列表给前端。
    $user_list = [];
    foreach ($table2 as $row){
        $user_list[] = $row['uname'];
    }
    $message =[
        'type'=>'logout',
        'from'=>'系统',
        'user_list'=>$user_list,
        'content'=>$uname,
    ];
    $data = json_encode($message);
    foreach ($ws->connections as $fds){
        //跳过关闭的这个。
        if($fd == $fd){
            continue;
        }
        $ws->push($fds, "{$data}");
    }
});

$ws->start();
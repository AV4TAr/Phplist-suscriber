<?php

require_once("Phplist_Suscribe.php");

$list = new Phplist_Suscribe('./');
$userId = $list->createUser('test2@example.com',array('name'=>'Test User'));
$list->subscribe($userId,1);
//$list->setAttributes('test@example.com',array('name'=>'Bob Jones',"country"=>"Canada"));
$members = $list->getSubscriberIds(1);
foreach ($members as $userId) {
  $email = $list->id2email($userId);
  print "$email is subscribed to list 1\n";
}
unset($list);
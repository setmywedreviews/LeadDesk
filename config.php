<?php
return [
 'db'=>[
  'host'=>getenv('MYSQLHOST') ?: getenv('SMW_DB_HOST') ?: '127.0.0.1',
  'port'=>getenv('MYSQLPORT') ?: getenv('SMW_DB_PORT') ?: '3306',
  'name'=>getenv('MYSQLDATABASE') ?: getenv('SMW_DB_NAME') ?: 'setmywed_leaddesk',
  'user'=>getenv('MYSQLUSER') ?: getenv('SMW_DB_USER') ?: 'root',
  'pass'=>getenv('MYSQLPASSWORD') ?: getenv('SMW_DB_PASS') ?: ''
 ],
 'target'=>50
];

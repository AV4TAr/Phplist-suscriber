<?php

/*

  phplist Class

  Written by Ravis (http://www.ravis.org/) 
  March 29th, 2006
 
  Modified by Diego Sapriza (http://about.me/diego.sapriza)
  September, 2012
  

  -------------------------------------------------
  SUMMARY:
  This class provides a simple way to interact with phpList in your own programs (eg: adding a user
  who just purchased something from your website to a mailing list).

  -------------------------------------------------
  DISCLAIMER:
  All the standard rules apply. No responsibility for loss of data, corruption of data, alien abductions,
  watching bad movies, unruly children, etc etc. Note that I'm pretty busy and I really don't have time
  to support this code, so please don't contact me asking for help implementing it. I release it simply
  because I thought it might be useful. I know absolutely nothing about phpList, really, I just installed
  it today. The queries here are mostly ripped from the phpList import debug info, the rest of the code is
  mine (although I wouldn't be surprised if it matches closely the phpList import code).

  -------------------------------------------------
  REQUIREMENTS:
  This function makes use of the PEAR DB abstraction layer, which makes dealing with databases a lot
  nicer. If you like there's nothing to stop you from modifying the $db-> commands to standard PHP
  mysql functions, but since I use the PEAR DB layer for pretty much everything, I used it here too.
  In fact, you could even use the DB layer included with phpList, I didn't because I wanted to keep
  the code as independent as possible.

  -------------------------------------------------
  FUNCTION REFERENCE:

  phpList(path)  --  The class constructor
  Parameters:
  path - optional, the path to your installation of phpList. This allows the class to grab your
  config settings (mainly your database un/pw/etc). If you choose not to specify this value
  the class will assume it's in the phpList directory and look there.
  Returns:
  nothing. constructors don't return :-)

  getSubscriberIds(list)
  Parameters:
  list - the phpList mailing list ID
  Returns:
  An array of phpList user IDs currently subscribed to that list

  setAttributes(user,attributes[,overwrite])
  Parameters:
  user - the phpList user ID or email address of the user you want to modify attributes for
  attributes - An array of NAME=>VALUE pairs. NAME should be a pre-defined attribute in
  phpList. If it doesn't exist, the attribute will be skipped (this function will NOT create
  attributes for you). VALUE is a text field with the value of the attribute. There's a
  special case built in for when NAME==country, the function will do a lookup to determine
  the country ID so that country fields will be normalized. If you specify a country that
  doesn't already exist in phpList, the attribute will be skipped (this function will NOT
  create countries for you).
  overwrite - a boolean value indicating if you want to overwrite existing attribute values or not.
  defaults to false (values will not be overwritten).
  Returns:
  A boolean value indicating if the update was successful or not

  email2id(email)
  Parameters:
  email - the email address you want to find the phpList user ID for
  Returns:
  The phpList user ID that corrosponds to the requested email address, or false if not found

  id2email(userId)
  Parameters:
  userId - the phpList user ID you want to get the email address for
  Returns:
  A string containing the email address or false if not found

  createUser(email[,attributes])
  Parameters:
  email - the email address for the user you're creating
  attributes - optional, attributes for this user (see docs for function setAttributes for details)
  Returns:
  The user ID for the newly created user, or false on failure.

  subscribe(user,list)
  Parameters:
  user - the phpList user ID or email address of the user you want to modify attributes for
  list - the phpList mailing list ID
  Returns:
  A boolean indicating success (TRUE) or failure (FALSE)

  unsubscribe(user[,list])
  Parameters:
  user - the phpList user ID or email address of the user you want to modify attributes for
  list - optional, the phpList mailing list ID. If no list ID is provided, the user is removed from ALL lists
  Returns:
  A boolean indicating success (TRUE) or failure (FALSE)

  -------------------------------------------------
  PARAMETER NOTES:
  Most functions accept either an email address or a phpList user ID. If you choose to
  provide a phpList user ID, that user must exist. If you provide an email address and
  the user doesn't exist, it will be created.

  List is the ID of the list you want to subscribe the user to. No verification is done on this
  field, it's up to you to make sure you pass it a valid ID.

  -------------------------------------------------
  EXAMPLE:

  $list = new phpList('/path/to/phplist/install/dir/');
  $userId = $list->createUser('test@example.com',array('name'=>'Test User'));
  $list->subscribe($userId,123);
  $list->setAttributes('test@example.com',array('name'=>'Bob Jones',"country"=>"Canada"));
  $members = $list->getSubscriberIds(123);
  foreach ($members as $userId) {
  $email = $list->id2email($userId);
  print "$email is subscribed to list 123\n";
  }
  unset($list);

  -------------------------------------------------
  MISSING FUNCTIONALITY:
  There's a lot of missing functionality that could be added by interested users. I've created
  the functions I needed to perform my tasks, but things like sending confirmations to new users,
  adding and removing attributes, etc etc could add a lot of value. If you make changes, please
  post them to http://www.phplist.com/forums/viewtopic.php?p=16091 for everyone to share. Thanks!

 */

class Phplist_Suscribe {

    private $db;
    private $tablePrefix;
    private $userTablePrefix;

    /**
     * 
     * @param string $pathToPhpList the path to your installation of phpList
     */
    function __construct($pathToPhpList = NULL) {
        // if no path specified, assume this file is in thephplist dir
        if (empty($pathToPhpList))
            $pathToPhpList = dirname(__FILE__);

        // Include your phpList config.php file here
        require("$pathToPhpList/config/config.php");
        $this->tablePrefix = $table_prefix;
        $this->userTablePrefix = $usertable_prefix;

        // Setup and connect to the database (this only needs to be done once per session
        // You don't need to change anything here.
        include_once("DB.php");
        $this->db = DB::connect("mysql://$database_user:$database_password@$database_host/$database_name");
        if (DB::isError($this->db))
            die($this->db->getMessage());
        $this->db->setFetchMode(DB_FETCHMODE_ASSOC);
    }

    /**
     * get suscribers from a list
     * @param integer $listId
     * @return array of email addresses suscribed to this list
     */
    function getSubscriberIds($listId) {
        $sql = "SELECT userid
              FROM " . $this->tablePrefix . "listuser
             WHERE listid='" . addslashes($listId) . "'";
        $userIds = $this->db->getCol($sql);
        return($userIds);
    }

    /**
     * Sets attributes to a user
     * @param mixed $userIdOrEmail integer of the id of the user or string email address of the user
     * @param array $attributes
     * @param bool $overwrite
     * @return bool
     */
    function setAttributes($userIdOrEmail, $attributes, $overwrite = false) {
        if (is_numeric($userIdOrEmail))
            $userId = $userIdOrEmail;
        else
            $userId = $this->email2id($userIdOrEmail);
        if (empty($userId))
            return(false);

        // if attributes were specified, try and map them to attribute ids in the db
        $attributeIds = array();
        if (is_array($attributes)) {
            foreach ($attributes as $name => $value) {
                $sql = "SELECT id FROM " . $this->userTablePrefix . "attribute WHERE name='" . addslashes($name) . "'";
                $id = $this->db->getOne($sql);
                if ($id)
                    $attributeIds[$name] = $id;
            }
        }
        // insert attributes (if any) for the user
        foreach ($attributeIds as $name => $id) {
            $value = $attributes[$name];
            // special case for countries - get the country id instead of using the name
            if (strtolower($name) == "country") {
                $sql = "SELECT id FROM " . $this->tablePrefix . "listattr_countries WHERE name='" . addslashes($value) . "'";
                $value = $this->db->getOne($sql);
                // if the country name wasn't found, skip
                if (empty($value))
                    continue;
            }
            if ($overwrite)
                $sql = "REPLACE INTO ";
            else
                $sql = "INSERT IGNORE INTO ";
            $sql .= $this->userTablePrefix . "user_attribute (attributeid,userid,value) values ('" . addslashes($id) . "','" . addslashes($userId) . "','" . addslashes($value) . "')";
            $result = $this->db->query($sql);
        }
        return(true);
    }

    /**
     * get a user using his email address
     * @param string $email
     * @return array
     */
    function email2id($email) {
        $sql = "SELECT id FROM " . $this->userTablePrefix . "user WHERE email='" . addslashes($email) . "'";
        $userId = $this->db->getOne($sql);
        if (empty($userId))
            return(false);
        return($userId);
    }

    /**
     * get a user using his user id
     * @param int $userId
     * @return array
     */
    function id2email($userId) {
        $sql = "SELECT email FROM " . $this->userTablePrefix . "user WHERE id='" . addslashes($userId) . "'";
        $email = $this->db->getOne($sql);
        if (empty($email))
            return(false);
        return($email);
    }

    /**
     * Creates a user (email) in the system.
     * @param string $email email of the user
     * @param array $attributes Attributes to add
     * @return type
     */
    function createUser($email, $attributes = false) {
        // create a unique id for the user (and make sure it's unique in the database)
        do {
            $uniqueId = md5(uniqid(mt_rand(0, 1000) . $email));
            $sql = "SELECT COUNT(*) FROM " . $this->userTablePrefix . "user WHERE uniqid='" . addslashes($uniqueId) . "'";
            $exists = $this->db->getOne($sql);
        } while ($exists);

        // insert the user
        $sql = "INSERT INTO " . $this->userTablePrefix . "user (email,entered,confirmed,uniqid,htmlemail) values ('" . addslashes($email) . "',now(),1,'" . addslashes($uniqueId) . "',1)";
        $result = $this->db->query($sql);

        // add a note saying we imported them manually
        $sql = "INSERT INTO " . $this->userTablePrefix . "user_history (userid,date,summary) values('" . addslashes($userId) . "',now(),'Import via phpList Class')";
        $result = $this->db->query($sql);

        // get the new user id
        $sql = "SELECT id FROM " . $this->userTablePrefix . "user WHERE uniqid='" . addslashes($uniqueId) . "'";
        $userId = $this->db->getOne($sql);

        // set attributes if any
        if ($attributes)
            $this->setAttributes($userId, $attributes);

        return($userId);
    }

    // 
    /**
     * subscribe a user to a list - accepts either a user id or an email 
     * user must exist
     * @param type $userIdOrEmail
     * @param type $listId
     * @return type
     */
    function subscribe($userIdOrEmail, $listId) {
        if (is_numeric($userIdOrEmail))
            $userId = $userIdOrEmail;
        else
            $userId = $this->email2id($userIdOrEmail);
        if (empty($userId))
            return(false);

        // subscribe them to the specified list
        $sql = "INSERT IGNORE INTO " . $this->tablePrefix . "listuser (userid,listid,entered) VALUES ('" . addslashes($userId) . "','" . addslashes($listId) . "',now())";
        $result = $this->db->query($sql);

        // add a note saying we subscribed them manually
        $sql = "INSERT INTO " . $this->userTablePrefix . "user_history (userid,date,summary) values('" . addslashes($userId) . "',now(),'Subscribed to list " . addslashes($listId) . " via phpList Class')";
        $result = $this->db->query($sql);

        return(true);
    }

    // 
    /**
     * unsubscribe an email addy from the list, if no list id provided, 
     * unsubscribe the user from all lists
     * @param mixed $userIdOrEmail integer or string (email)
     * @param integer $listId
     * @return bool
     */
    function unsubscribe($userIdOrEmail, $listId = NULL) {
        if (is_numeric($userIdOrEmail))
            $userId = $userIdOrEmail;
        else
            $userId = $this->email2id($userIdOrEmail);
        if (empty($userId))
            return(false);

        // unsubscribe them
        $sql = "DELETE FROM " . $this->tablePrefix . "listuser
             WHERE userid='" . addslashes($userId) . "'";
        if ($listId)
            $sql .= " AND listid='" . addslashes($listId) . "'";
        $this->db->query($sql);

        // add a note saying we unsubscribed them manually
        $sql = "INSERT INTO " . $this->userTablePrefix . "user_history (userid,date,summary) values('" . addslashes($userId) . "',now(),'Unsubscribed from list " . addslashes($listId) . " via phpList Class')";
        $result = $this->db->query($sql);

        return(true);
    }

}

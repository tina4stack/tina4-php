<?php
//Used to load single row of data from database with the specified id (Primary Key)
$user = new User();
$user->id = 1;
$user->load();
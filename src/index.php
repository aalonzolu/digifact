<?php 

namespace aalonzolu\Digifact;

class Digifact
{
    private $username;
    private $password;
    public function __construct($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
        return "Hola mundo";
    }
}

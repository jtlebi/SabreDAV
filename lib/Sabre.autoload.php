<?php

/**
 * SabreDAV's PHP autoloader
 *
 * If you love the autoloader, and don't care as much about performance, this
 * file register a new autoload function using spl_autoload_register. 
 *
 * @package Sabre
 * @subpackage DAV
 * @version $Id: Sabre.includes.php 420 2009-06-22 19:21:02Z evertpot $
 * @copyright Copyright (C) 2007-2009 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */

function Sabre_DAV_autoload($className) {

    if(strpos($className,'Sabre_')===0) {

        include dirname(__FILE__) . '/' . str_replace('_','/',$className) . '.php';

    }

}

spl_autoload_register('Sabre_DAV_autoload');

?>
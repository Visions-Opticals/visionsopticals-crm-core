<?php

return array(


    'pdf' => array(
        'enabled' => true,
        'binary'  =>"/usr/bin/wkhtmltopdf", //server
//         'binary' =>  "/usr/local/bin/wkhtmltopdf", //local
        // 'binary' => base_path('vendor/bin/wkhtmltopdf-amd64'), //server
        'timeout' => false,
        'options' => array(),
        'env'     => array(),
    ),
    'image' => array(
        'enabled' => true,
        'binary'  => '/usr/bin/wkhtmltoimage', //server
//        '/usr/local/bin/wkhtmltoimage', //local
        'timeout' => false,
        'options' => array(),
        'env'     => array(),
    ),


);

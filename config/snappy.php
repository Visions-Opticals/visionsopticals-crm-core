<?php

return array(


    'pdf' => array(
        'enabled' => true,
        'binary'  =>"/usr/local/bin/wkhtmltopdf",
//            "/usr/local/bin/wkhtmltopdf",
//base_path('vendor/bin/wkhtmltopdf-amd64'),
        'timeout' => false,
        'options' => array(),
        'env'     => array(),
    ),
    'image' => array(
        'enabled' => true,
        'binary'  => '/usr/local/bin/wkhtmltoimage',
        'timeout' => false,
        'options' => array(),
        'env'     => array(),
    ),


);

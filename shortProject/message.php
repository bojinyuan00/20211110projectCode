<?php
return array(
    'wechat' => array(
        "templates" => array(

        ),
    ),
    'sms' => array(
        'code_length' => 6,//验证码长度
        'template' => array(
            array(
                'label' => '平台',
                "type" => 'radio',
                "sign" => "platform",
                "config" => false,
                "radios" => array(
                    array(
                        "name" => "阿里云",
                        "value" => 'aliyun',
                        "key" => 'aliyun',
                    ),
                )
            ),
            array(
                'label' => 'AccessKeyID',
                "type" => 'text',
                "config" => false,
                "placeholder" => "请输入短信平台AccessKeyID",
                "sign" => "username"
            ),
            array(
                'label' => 'AccessKeySecret',
                "type" => 'text',
                "config" => false,
                "placeholder" => "请输入短信平台AccessKeySecret",
                "sign" => "password"
            ),
            array(
                'label' => '签名',
                "type" => 'text',
                "config" => false,
                "placeholder" => "请输入短信签名",
                "sign" => "sign"
            ),
            array(
                "type" => 'text',
                "config" => true,
                "label" => "验证码模板",
                "placeholder" => "请输入验证码短信模板",
                "sign" => "code"
            ),
        ),
    ),
);
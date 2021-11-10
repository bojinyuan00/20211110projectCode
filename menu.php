<?php
return array(
    "name" => "管理后台",
    "icon" => "ios-desktop",
    "show_parent" => false,
    "plugin" => "trace",
    "sign" => "trace",
    "base_path" => is_plugin_in_development("short") ? "http://localhost:8082/addons/trace/admin/?v=".time() : SITE_URL."addons/trace/admin/?v=".time(),
    "iframe" => true,
    "menus" => array(
        //union_type 统一后台的菜单类型  self_type 自己后台的菜单类型
        array(
            "sign" => "dashboard",
            "name" => "首页",
            "self_type" => "path",//path parent iframe url
            "union_type" => "iframepath",//path url parent iframepath iframe
            "path" => "/dashboard",
            "union" => true,
            "self" => true,
            "icon" => "md-home",
            "children" => array()
        ),
        array(
            "sign" => "company",
            "name" => "公司",
            "self_type" => "path",//path parent iframe url
            "union_type" => "iframepath",//path url parent iframepath iframe
            "path" => "/company",
            "union" => true,
            "self" => true,
            "icon" => "md-home",
            "children" => array()
        )
    )
);
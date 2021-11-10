/*
 Navicat Premium Data Transfer

 Source Server         : 测试服务器
 Source Server Type    : MySQL
 Source Server Version : 50562
 Source Host           : 192.168.1.245:3306
 Source Schema         : beesh

 Target Server Type    : MySQL
 Target Server Version : 50562
 File Encoding         : 65001

 Date: 03/03/2021 18:37:39
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for ims_short_url_company
-- ----------------------------
DROP TABLE IF EXISTS `ims_short_url_company`;
CREATE TABLE `ims_short_url_company`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键id',
  `uniacid` int(11) NOT NULL DEFAULT 0 COMMENT '项目id',
  `estimate_count` bigint(15) NOT NULL DEFAULT 0 COMMENT '预计存储数据量',
  `use_count` bigint(15) NOT NULL DEFAULT 0 COMMENT '已使用的数据量',
  `description` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL COMMENT '内容描述 -- 用来记录一些详细信息	',
  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '修改时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = '短链接企业基础信息表 ' ROW_FORMAT = Compact;

-- ----------------------------
-- Records of ims_short_url_company
-- ----------------------------
INSERT INTO `ims_short_url_company` VALUES (1, 18, 1000000000, 0, NULL, '2021-03-03 18:35:44', '2021-03-03 18:35:41');

-- ----------------------------
-- Table structure for ims_short_url_company_database
-- ----------------------------
DROP TABLE IF EXISTS `ims_short_url_company_database`;
CREATE TABLE `ims_short_url_company_database`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uniacid` int(11) NOT NULL DEFAULT 0 COMMENT '项目id',
  `database_number` int(11) NOT NULL DEFAULT 1 COMMENT '数据库编号',
  `content` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL COMMENT '数据库详细配置信息 json字符串',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = '短链接企业数据库分库信息表' ROW_FORMAT = Compact;

-- ----------------------------
-- Table structure for ims_short_url_domain
-- ----------------------------
DROP TABLE IF EXISTS `ims_short_url_domain`;
CREATE TABLE `ims_short_url_domain`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uniacid` int(11) NOT NULL COMMENT '项目id',
  `company_id` int(11) NOT NULL COMMENT '企业id',
  `default` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否为企业的默认域名',
  `name` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '域名',
  `https` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否为https请求 0 否  1是',
  `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '修改时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = Compact;

-- ----------------------------
-- Records of ims_short_url_domain
-- ----------------------------
INSERT INTO `ims_short_url_domain` VALUES (1, 18, 1, 1, 'cloud.be-shell.com', 0, '2021-03-03 18:36:02', '0000-00-00 00:00:00');

-- ----------------------------
-- Table structure for ims_short_url_task
-- ----------------------------
DROP TABLE IF EXISTS `ims_short_url_task`;
CREATE TABLE `ims_short_url_task`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键id',
  `uniacid` int(11) NOT NULL DEFAULT 0 COMMENT '项目id',
  `status` tinyint(2) NOT NULL DEFAULT 0 COMMENT '转码任务状态 0-文件未上传 1文件已上传,未解析 2文件解析中 3文件已解析，任务已完成 -1文件解析失败',
  `short_file_path` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '短链接文件存储路径',
  `long_file_path` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '长链接文件存储路径',
  `del_flag` tinyint(2) NOT NULL COMMENT '软删除状态  0未删除 1已经删除',
  `create_time` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` timestamp NULL DEFAULT NULL COMMENT '修改时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = '短链接任务表' ROW_FORMAT = Compact;

-- ----------------------------
-- Records of ims_short_url_task
-- ----------------------------
INSERT INTO `ims_short_url_task` VALUES (1, 18, 0, '', '', 0, '2021-03-02 18:50:02', '2021-03-02 18:51:39');

SET FOREIGN_KEY_CHECKS = 1;

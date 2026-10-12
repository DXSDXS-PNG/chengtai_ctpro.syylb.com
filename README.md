# 承台应用管理平台

<p>
  <a href="https://github.com/DXSDXS-PNG/chengtai_ctpro.syylb.com"><img src="https://img.shields.io/badge/%E6%BA%90%E7%A0%81-%E6%B5%8F%E8%A7%88-brightgreen.svg" alt="浏览源码"></a>
  <img src="https://img.shields.io/badge/PHP-8.3-777bb3.svg" alt="PHP 8.3">
  <img src="https://img.shields.io/badge/ThinkPHP-8.0-336c9f.svg" alt="ThinkPHP 8">
</p>

承台应用管理平台 —— 统一管理平台（插件底座后端）。

## ⬇️ 源码下载

- [platform-server/](./platform-server) —— 后端源码（ThinkPHP 8）
- [platform/](./platform) —— 前端部署目录

## 简介

- 定位：统一管理平台，插件底座后端
- 技术栈：ThinkPHP 8 / PHP >= 8.2 / think-orm 3.x
- 用途：作为承台应用（插件）的底座，统一管理应用注册、鉴权、域名、支付、审计等能力

## ⚙️ 安装教程

1. **PHP 版本**：8.3

2. **伪静态**：

   ```
   location / {
       if (!-e $request_filename) {
           rewrite ^(.*)$ /index.php?s=$1 last;
       }
   }
   ```

3. **运行目录**：`/platform-server/public`

4. **默认文档**（`index.html` 在前即可）：

   ```
   index.html
   index.php
   index.htm
   default.php
   default.htm
   default.html
   ```

5. 访问 `域名/install` 进行安装。

## 📦 目录结构

| 目录 | 说明 |
| --- | --- |
| `platform/` | 前端部署目录 |
| `platform-server/` | 后端源码（加密发布） |

## 🖥️ 服务器推荐

- **免备案**（香港、美国等国外服务器）：<https://www.rainyun.com/dxsdxs_>
- **需备案**（中国大陆）腾讯云：<https://curl.qcloud.com/ymIUYkNj>

## 🧩 官方付费插件 & 功能 & 服务

以下插件请访问（微信小程序 **次世代网络** 或添加作者微信 **A1565182** 获取）：

- 活码插件
- 聚合导航页插件
- 私域裂变插件
- 企微投诉反馈插件
- 微信外链插件（抖音卡片）
- 心理健康测试运营插件
- 文章应用插件
- 朋友圈访客插件

## 🛠️ 付费定制服务

- 付费基于承台新增功能或修改功能
- 付费安装部署承台
- 抖音小程序卡片制作

请联系作者微信：**A1565182**，备用：**yebali0011**（备注：定制）

## 📄 说明

本包为个人版，后端源码以加密形式分发，仅供部署使用。

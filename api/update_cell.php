<?php
require __DIR__ . '/common.php';
respond_json([
    'error' => 'endpoint_replaced',
    'message' => '该接口已下线，请改用新的 REST API。'], 410);

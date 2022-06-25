<?php

namespace icy8\SocketIO;

class Request
{
    protected $buffer     = '';
    public    $headers    = [];
    public    $header_raw = '';
    public    $url;
    public    $protocal   = 'HTTP/1.1';
    public    $query      = [];
    public    $method     = 'GET';
    public    $cookies    = []; // @todo 解析cookie
    public    $connection = null;
    public    $raw_data   = '';

    public function __construct($connection, $buffer)
    {
        $this->connection = $connection;
        $this->buffer     = $buffer;
        $buffer_lines     = explode("\r\n", $buffer);
        $request_line     = $buffer_lines[0];
        $header_lines     = array_slice($buffer_lines, 1);
        $buffer_groups    = explode("\r\n\r\n", implode("\r\n", $header_lines));
        $header_raw       = $buffer_groups[0];
        $this->raw_data   = $buffer_groups[1];
        $this->header_raw = $header_raw;
        $header_lines     = explode("\r\n", $header_raw);
        $request_item     = explode(" ", $request_line);
        $this->method     = trim($request_item[0]);
        $this->url        = trim($request_item[1]);
        $this->protocal   = trim($request_item[2] ?? '');
        parse_str(parse_url($this->url, PHP_URL_QUERY), $this->query);
        foreach ($header_lines as $header) {
            if (empty($header)) {
                continue;
            }
            list($name, $value) = explode(":", $header, 2);
            $this->headers[strtolower($name)] = trim($value);
        }
    }

    public function getRaw($type = '')
    {
        switch (strtolower($type)) {
            case 'query':
                parse_str($this->raw_data, $res);
                return $res;
                break;
            case 'json':
                return json_decode($this->raw_data, true);
                break;
            case "ini":
                return parse_ini_string($this->raw_data);
                break;
            default:
                return $this->raw_data;
        }
    }
}
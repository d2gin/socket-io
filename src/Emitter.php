<?php

namespace icy8\SocketIO;
/**
 * 代码来自workerman/phpsocket.io
 * Class Emitter
 * @package icy8\SocketIO
 */
class Emitter
{
    public function __construct()
    {
    }

    public function __destruct()
    {
    }

    /**
     * [event=>[[listener1, once?], [listener2,once?], ..], ..]
     */
    protected $_eventListenerMap = array();

    public function on($event_name, $listener)
    {
        $this->emit('newListener', $event_name, $listener);
        $this->_eventListenerMap[$event_name][] = array($listener, 0);
        return $this;
    }

    public function once($event_name, $listener)
    {
        $this->_eventListenerMap[$event_name][] = array($listener, 1);
        return $this;
    }

    public function removeListener($event_name, $listener)
    {
        if (!isset($this->_eventListenerMap[$event_name])) {
            return $this;
        }
        foreach ($this->_eventListenerMap[$event_name] as $key => $item) {
            if ($item[0] === $listener) {
                $this->emit('removeListener', $event_name, $listener);
                unset($this->_eventListenerMap[$event_name][$key]);
            }
        }
        if (empty($this->_eventListenerMap[$event_name])) {
            unset($this->_eventListenerMap[$event_name]);
        }
        return $this;
    }

    public function removeAllListeners($event_name = null)
    {
        $this->emit('removeListener', $event_name);
        if (null === $event_name) {
            $this->_eventListenerMap = array();
            return $this;
        }
        unset($this->_eventListenerMap[$event_name]);
        return $this;
    }

    public function listeners($event_name)
    {
        if (empty($this->_eventListenerMap[$event_name])) {
            return array();
        }
        $listeners = array();
        foreach ($this->_eventListenerMap[$event_name] as $item) {
            $listeners[] = $item[0];
        }
        return $listeners;
    }

    public function emit($event_name = null)
    {
        if (empty($event_name) || empty($this->_eventListenerMap[$event_name])) {
            return false;
        }
        $result = [];
        $once   = false;
        foreach ($this->_eventListenerMap[$event_name] as $key => $item) {
            $args = func_get_args();
            unset($args[0]);
            $result[$key] = call_user_func_array($item[0], $args);
            // once ?
            if ($item[1]) {
                $once = true;
                // 执行后就销毁
                unset($this->_eventListenerMap[$event_name][$key]);
                if (empty($this->_eventListenerMap[$event_name])) {
                    unset($this->_eventListenerMap[$event_name]);
                }
                // 中断
                break;
            }
        }
        return $once ? end($result) : $result;
    }

}

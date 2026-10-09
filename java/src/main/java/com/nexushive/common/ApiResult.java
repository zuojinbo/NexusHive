package com.nexushive.common;

import java.util.LinkedHashMap;
import java.util.Map;

/** ThinkPHP Api::result envelope: code / msg / time / data. */
public final class ApiResult {
    private final int code;
    private final String msg;
    private final long time;
    private final Object data;

    private ApiResult(int code, String msg, Object data) {
        this.code = code;
        this.msg = msg == null ? "" : msg;
        this.time = System.currentTimeMillis() / 1000;
        this.data = data;
    }

    public static ApiResult ok(String msg, Object data) {
        return new ApiResult(1, msg, data);
    }

    public static ApiResult ok(Object data) {
        return ok("", data);
    }

    public static ApiResult fail(String msg) {
        return fail(msg, null, 0);
    }

    public static ApiResult fail(String msg, Object data, int code) {
        return new ApiResult(code, msg, data);
    }

    public Map<String, Object> toMap() {
        Map<String, Object> map = new LinkedHashMap<>();
        map.put("code", code);
        map.put("msg", msg);
        map.put("time", time);
        map.put("data", data);
        return map;
    }

    public int getCode() { return code; }
    public String getMsg() { return msg; }
    public long getTime() { return time; }
    public Object getData() { return data; }
}

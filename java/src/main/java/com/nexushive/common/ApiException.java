package com.nexushive.common;

public class ApiException extends RuntimeException {
    private final int code;
    private final Object data;

    public ApiException(String message) {
        this(message, null, 0);
    }

    public ApiException(String message, Object data, int code) {
        super(message);
        this.code = code;
        this.data = data;
    }

    public int getCode() { return code; }
    public Object getData() { return data; }
}

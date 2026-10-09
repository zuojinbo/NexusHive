package com.nexushive.web;

import com.nexushive.common.ApiException;
import com.nexushive.common.ApiResult;
import org.springframework.http.MediaType;
import org.springframework.web.bind.annotation.ExceptionHandler;
import org.springframework.web.bind.annotation.RestControllerAdvice;

import java.util.Map;

@RestControllerAdvice
public class ApiExceptionHandler {
    @ExceptionHandler(ApiException.class)
    public Map<String, Object> api(ApiException ex) {
        return ApiResult.fail(ex.getMessage(), ex.getData(), ex.getCode()).toMap();
    }

    @ExceptionHandler(Exception.class)
    public Map<String, Object> other(Exception ex) {
        String msg = ex.getMessage() == null ? "页面错误！请稍后再试～" : ex.getMessage();
        return ApiResult.fail(msg).toMap();
    }
}

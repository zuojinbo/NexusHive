package com.nexushive.web;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import jakarta.servlet.http.HttpServletRequest;

import java.io.IOException;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** Merges query, form and JSON bodies, including ThinkPHP search[0][field] names. */
public final class Params {
    private static final Pattern NESTED = Pattern.compile("^([A-Za-z0-9_]+)\\[(\\d+)]\\[([A-Za-z0-9_]+)]$");

    private Params() {}

    public static Map<String, Object> read(HttpServletRequest request, ObjectMapper json) throws IOException {
        Map<String, Object> out = new LinkedHashMap<>();
        request.getParameterMap().forEach((key, values) -> {
            if (values == null || values.length == 0) return;
            Matcher nested = NESTED.matcher(key);
            if (nested.matches()) {
                String name = nested.group(1);
                int index = Integer.parseInt(nested.group(2));
                String field = nested.group(3);
                @SuppressWarnings("unchecked")
                List<Map<String, Object>> list = (List<Map<String, Object>>) out.computeIfAbsent(name, k -> new ArrayList<>());
                while (list.size() <= index) list.add(new LinkedHashMap<>());
                list.get(index).put(field, values[0]);
                return;
            }
            if (key.endsWith("[]")) {
                String name = key.substring(0, key.length() - 2);
                List<String> list = new ArrayList<>();
                for (String value : values) list.add(value);
                out.put(name, list);
                return;
            }
            out.put(key, values.length == 1 ? values[0] : List.of(values));
        });
        String type = request.getContentType();
        if (type != null && type.toLowerCase().contains("json")) {
            byte[] body = request.getInputStream().readAllBytes();
            if (body.length > 0) {
                JsonNode node = json.readTree(body);
                if (node.isObject()) {
                    node.fields().forEachRemaining(e -> out.put(e.getKey(), json.convertValue(e.getValue(), Object.class)));
                }
            }
        }
        return out;
    }

    public static String str(Map<String, Object> params, String key) {
        Object value = params.get(key);
        return value == null ? "" : String.valueOf(value);
    }

    public static boolean isPost(HttpServletRequest request) {
        return "POST".equalsIgnoreCase(request.getMethod());
    }

    public static List<String> ids(Map<String, Object> params) {
        Object raw = params.get("ids");
        List<String> ids = new ArrayList<>();
        if (raw instanceof List<?> list) {
            for (Object item : list) {
                if (item != null && !String.valueOf(item).isBlank()) ids.add(String.valueOf(item));
            }
        } else if (raw != null && !String.valueOf(raw).isBlank()) {
            for (String part : String.valueOf(raw).split(",")) {
                if (!part.isBlank()) ids.add(part.trim());
            }
        }
        return ids;
    }

    @SuppressWarnings("unchecked")
    public static List<Map<String, Object>> searches(Map<String, Object> params) {
        Object raw = params.get("search");
        if (!(raw instanceof List<?> list)) return List.of();
        List<Map<String, Object>> out = new ArrayList<>();
        for (Object item : list) {
            if (item instanceof Map<?, ?> map) out.add((Map<String, Object>) map);
        }
        return out;
    }
}

package com.nexushive.db;

import com.nexushive.config.AppProperties;
import jakarta.annotation.PostConstruct;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.stereotype.Component;

import java.util.Collections;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;

@Component
public class SchemaMeta {
    private final JdbcTemplate jdbc;
    private final AppProperties props;
    private final Map<String, Set<String>> columns = new HashMap<>();
    private final Map<String, Map<String, String>> types = new HashMap<>();
    private final Map<String, String> primaryKeys = new HashMap<>();

    public SchemaMeta(JdbcTemplate jdbc, AppProperties props) {
        this.jdbc = jdbc;
        this.props = props;
    }

    @PostConstruct
    public void load() {
        String schema = jdbc.queryForObject("SELECT DATABASE()", String.class);
        List<Map<String, Object>> rows = jdbc.queryForList(
                "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_KEY, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?",
                schema);
        for (Map<String, Object> row : rows) {
            String table = String.valueOf(row.get("TABLE_NAME")).toLowerCase(Locale.ROOT);
            String column = String.valueOf(row.get("COLUMN_NAME"));
            columns.computeIfAbsent(table, k -> new HashSet<>()).add(column);
            types.computeIfAbsent(table, k -> new HashMap<>()).put(column, String.valueOf(row.get("DATA_TYPE")).toLowerCase(Locale.ROOT));
            if ("PRI".equals(String.valueOf(row.get("COLUMN_KEY"))) && !primaryKeys.containsKey(table)) {
                primaryKeys.put(table, column);
            }
        }
    }

    public boolean hasTable(String logical) {
        return columns.containsKey(props.table(logical).toLowerCase(Locale.ROOT));
    }

    public boolean hasColumn(String logical, String column) {
        Set<String> cols = columns.getOrDefault(props.table(logical).toLowerCase(Locale.ROOT), Set.of());
        return cols.contains(column);
    }

    public boolean isDateTime(String logical, String column) {
        String type = types.getOrDefault(props.table(logical).toLowerCase(Locale.ROOT), Map.of()).get(column);
        return type != null && (type.contains("date") || type.contains("time"));
    }

    public Set<String> columns(String logical) {
        return columns.getOrDefault(props.table(logical).toLowerCase(Locale.ROOT), Set.of());
    }

    public String pk(String logical) {
        return primaryKeys.getOrDefault(props.table(logical).toLowerCase(Locale.ROOT), "id");
    }

    public String physical(String logical) {
        if (!logical.matches("[a-zA-Z0-9_]+")) {
            throw new IllegalArgumentException("非法表名");
        }
        String physical = props.table(logical);
        if (!columns.containsKey(physical.toLowerCase(Locale.ROOT))) {
            throw new IllegalArgumentException("数据表不存在: " + physical);
        }
        return physical;
    }

    public void requireColumn(String logical, String column) {
        if (!column.matches("[A-Za-z0-9_]+") || !hasColumn(logical, column)) {
            throw new IllegalArgumentException("非法字段: " + column);
        }
    }

    public Map<String, Set<String>> snapshot() {
        return Collections.unmodifiableMap(columns);
    }
}

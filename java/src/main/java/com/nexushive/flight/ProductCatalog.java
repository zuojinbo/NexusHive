package com.nexushive.flight;

import com.nexushive.config.AppProperties;
import org.springframework.stereotype.Component;

import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** Reads config/dji_products.php so aircraft lists stay aligned with the PHP tree. */
@Component
public class ProductCatalog {
    private static final Pattern BLOCK = Pattern.compile("'(\\d+-\\d+-\\d+)'\\s*=>\\s*\\[(.*?)]", Pattern.DOTALL);
    private static final Pattern NAME = Pattern.compile("'name'\\s*=>\\s*'([^']*)'");
    private static final Pattern NAME_EN = Pattern.compile("'name_en'\\s*=>\\s*'([^']*)'");
    private static final Pattern INT = Pattern.compile("'(%s)'\\s*=>\\s*(\\d+)");

    private final AppProperties props;
    private volatile Map<String, Map<String, Object>> products = Map.of();

    public ProductCatalog(AppProperties props) {
        this.props = props;
    }

    public Map<String, Object> find(String key) {
        load();
        return products.get(key);
    }

    public List<Map<String, Object>> aircraftSeries() {
        load();
        Map<String, Series> series = new LinkedHashMap<>();
        series.put("M4", new Series("M4", "Matrice 4 系列", "Matrice 4 Series", 1, 99, 100, 103));
        series.put("M3", new Series("M3", "Matrice 3 系列", "Matrice 3 Series", 2, 91));
        series.put("M350", new Series("M350", "Matrice 350 系列", "Matrice 350 Series", 3, 89));
        series.put("M30", new Series("M30", "Matrice 30 系列", "Matrice 30 Series", 4, 67));
        series.put("M300", new Series("M300", "Matrice 300 系列", "Matrice 300 Series", 5, 60));
        series.put("Mavic", new Series("Mavic", "Mavic 3 系列", "Mavic 3 Series", 6, 77));
        Series other = new Series("Other", "其他型号", "Other", 99);
        List<Map<String, Object>> aircraft = products.values().stream()
                .filter(item -> intOf(item.get("domain")) == 0)
                .sorted(Comparator.comparingInt(item -> intOf(item.get("sub_type"))))
                .toList();
        for (Map<String, Object> item : aircraft) {
            Series bucket = other;
            int type = intOf(item.get("type"));
            for (Series candidate : series.values()) {
                if (candidate.types.contains(type)) {
                    bucket = candidate;
                    break;
                }
            }
            bucket.items.add(item);
        }
        List<Series> ordered = new ArrayList<>(series.values());
        if (!other.items.isEmpty()) ordered.add(other);
        ordered.sort(Comparator.comparingInt(s -> s.order));
        List<Map<String, Object>> out = new ArrayList<>();
        for (Series bucket : ordered) {
            if (bucket.items.isEmpty()) continue;
            Map<String, Object> row = new LinkedHashMap<>();
            row.put("series", bucket.key);
            row.put("series_name", bucket.name);
            row.put("series_name_en", bucket.nameEn);
            row.put("order", bucket.order);
            row.put("aircraft", bucket.items);
            out.add(row);
        }
        return out;
    }

    public int aircraftCount() {
        load();
        return (int) products.values().stream().filter(item -> intOf(item.get("domain")) == 0).count();
    }

    private void load() {
        if (!products.isEmpty()) return;
        synchronized (this) {
            if (!products.isEmpty()) return;
            Path root = Path.of(props.getPublicDir()).toAbsolutePath().normalize().getParent();
            Path file = root == null ? Path.of("config/dji_products.php") : root.resolve("config/dji_products.php");
            Map<String, Map<String, Object>> parsed = new LinkedHashMap<>();
            try {
                String text = Files.readString(file);
                Matcher blocks = BLOCK.matcher(text);
                while (blocks.find()) {
                    String key = blocks.group(1);
                    String body = blocks.group(2);
                    Map<String, Object> item = new LinkedHashMap<>();
                    item.put("value", key);
                    item.put("label", group(NAME, body, key));
                    item.put("label_en", group(NAME_EN, body, group(NAME, body, key)));
                    item.put("domain", integer(body, "domain"));
                    item.put("type", integer(body, "type"));
                    item.put("sub_type", integer(body, "sub_type"));
                    item.put("name", item.get("label"));
                    parsed.put(key, item);
                }
            } catch (Exception ignored) {
                parsed = Map.of();
            }
            products = parsed;
        }
    }

    private static String group(Pattern pattern, String body, String fallback) {
        Matcher matcher = pattern.matcher(body);
        return matcher.find() ? matcher.group(1) : fallback;
    }

    private static int integer(String body, String field) {
        Matcher matcher = Pattern.compile(String.format(INT.pattern(), field)).matcher(body);
        return matcher.find() ? Integer.parseInt(matcher.group(2)) : 0;
    }

    private static int intOf(Object value) {
        return value instanceof Number n ? n.intValue() : 0;
    }

    private static final class Series {
        final String key;
        final String name;
        final String nameEn;
        final int order;
        final List<Integer> types = new ArrayList<>();
        final List<Map<String, Object>> items = new ArrayList<>();

        Series(String key, String name, String nameEn, int order, int... types) {
            this.key = key;
            this.name = name;
            this.nameEn = nameEn;
            this.order = order;
            for (int type : types) this.types.add(type);
        }
    }
}

# 多阶段构建：编译用 Temurin 17 + Maven，运行只用 JRE 17。
FROM maven:3.9-eclipse-temurin-17 AS build
WORKDIR /src
COPY pom.xml .
COPY src ./src
RUN mvn -B -DskipTests package

FROM eclipse-temurin:17-jre-jammy
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY --from=build /src/target/nexushive-backend-1.0.0.jar /app/app.jar
ENV PUBLIC_DIR=/var/www/public
EXPOSE 8080
ENTRYPOINT ["java", "-jar", "/app/app.jar"]

<?php

namespace updater;

use ZipArchive;
use RuntimeException;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

/**
 * 更新安装器
 * 
 * 负责解压更新包、备份旧文件、执行更新、回滚等操作
 */
class UpdateInstaller
{
    /**
     * 项目根目录
     * @var string
     */
    protected string $rootPath;
    
    /**
     * 备份目录
     * @var string
     */
    protected string $backupPath;
    
    /**
     * 临时目录
     * @var string
     */
    protected string $tempPath;
    
    /**
     * 需要排除的文件/目录（不会被更新覆盖）
     * @var array
     */
    protected array $excludePaths = [];
    
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->rootPath = root_path();
        $this->backupPath = runtime_path() . 'backup/';
        $this->tempPath = runtime_path() . 'update_temp/';
        
        // 从配置加载排除路径
        $config = config('updater');
        $this->excludePaths = $config['exclude_paths'] ?? [
            '.env',
            'config/database.php',
            'config/updater.php',
            'runtime/',
            'public/storage/',
            'public/uploads/',
        ];
    }

    /**
     * 执行更新安装
     * 
     * @param string $packagePath 更新包路径
     * @param string $fromVersion 当前版本
     * @param string $toVersion   目标版本
     * @return bool
     * @throws RuntimeException
     */
    public function install(string $packagePath, string $fromVersion, string $toVersion): bool
    {
        // 1. 创建备份
        $backupDir = $this->createBackup($fromVersion);
        
        try {
            // 2. 解压更新包到临时目录
            $extractPath = $this->extractPackage($packagePath);
            
            // 3. 执行更新前脚本（如果存在）
            $this->runPreUpdateScript($extractPath);
            
            // 4. 复制文件到目标目录
            $this->copyFiles($extractPath, $this->rootPath);
            
            // 5. 执行更新后脚本（如果存在）
            $this->runPostUpdateScript($extractPath);
            
            // 6. 更新版本号
            $this->updateVersionFile($toVersion);
            
            // 7. 清理临时文件
            $this->cleanup($extractPath);
            
            return true;
        } catch (\Throwable $e) {
            // 回滚到备份
            $this->rollback($backupDir);
            throw $e;
        }
    }
    
    /**
     * 创建备份
     * 
     * @param string $version 当前版本号
     * @return string 备份目录路径
     * @throws RuntimeException
     */
    public function createBackup(string $version): string
    {
        $backupDir = $this->backupPath . date('Ymd_His') . '_v' . $version . '/';
        
        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0755, true)) {
                throw new RuntimeException('无法创建备份目录: ' . $backupDir);
            }
        }
        
        // 备份关键文件和目录
        $filesToBackup = [
            'app/',
            'config/',
            'extend/',
            'public/index.php',
            'composer.json',
            'composer.lock',
        ];
        
        foreach ($filesToBackup as $file) {
            $source = $this->rootPath . $file;
            $target = $backupDir . $file;
            
            if (is_dir($source)) {
                $this->copyDirectory($source, $target);
            } elseif (is_file($source)) {
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                copy($source, $target);
            }
        }
        
        return $backupDir;
    }
    
    /**
     * 解压更新包
     * 
     * @param string $packagePath 更新包路径
     * @return string 解压目录路径
     * @throws RuntimeException
     */
    public function extractPackage(string $packagePath): string
    {
        $extractPath = $this->tempPath . uniqid('update_') . '/';
        
        if (!is_dir($extractPath)) {
            if (!mkdir($extractPath, 0755, true)) {
                throw new RuntimeException('无法创建临时目录: ' . $extractPath);
            }
        }
        
        $zip = new ZipArchive();
        $result = $zip->open($packagePath);
        
        if ($result !== true) {
            throw new RuntimeException('无法打开更新包，错误代码: ' . $result);
        }
        
        if (!$zip->extractTo($extractPath)) {
            $zip->close();
            throw new RuntimeException('解压更新包失败');
        }
        
        $zip->close();
        
        return $extractPath;
    }
    
    /**
     * 复制文件到目标目录
     * 
     * @param string $source 源目录
     * @param string $target 目标目录
     * @return void
     */
    public function copyFiles(string $source, string $target): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source));
            $targetPath = $target . $relativePath;
            
            // 检查是否在排除列表中
            if ($this->isExcluded($relativePath)) {
                continue;
            }
            
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                $dir = dirname($targetPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                copy($item->getPathname(), $targetPath);
            }
        }
    }
    
    /**
     * 检查路径是否在排除列表中
     * 
     * @param string $path 相对路径
     * @return bool
     */
    public function isExcluded(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        
        foreach ($this->excludePaths as $exclude) {
            $exclude = rtrim($exclude, '/');
            
            // 精确匹配或目录前缀匹配
            if ($path === $exclude || str_starts_with($path, $exclude . '/') || str_starts_with($path, $exclude)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 执行更新前脚本
     * 
     * @param string $extractPath 解压目录
     * @return void
     * @throws RuntimeException
     */
    protected function runPreUpdateScript(string $extractPath): void
    {
        $script = $extractPath . 'scripts/pre_update.php';
        if (file_exists($script)) {
            try {
                include $script;
            } catch (\Throwable $e) {
                throw new RuntimeException('更新前脚本执行失败: ' . ($e->getMessage() ?: get_class($e)));
            }
        }
    }
    
    /**
     * 执行更新后脚本
     * 
     * @param string $extractPath 解压目录
     * @return void
     * @throws RuntimeException
     */
    protected function runPostUpdateScript(string $extractPath): void
    {
        $script = $extractPath . 'scripts/post_update.php';
        if (file_exists($script)) {
            try {
                include $script;
            } catch (\Throwable $e) {
                throw new RuntimeException('更新后脚本执行失败: ' . ($e->getMessage() ?: get_class($e)));
            }
        }
        
        // 执行数据库迁移（如果命令可用）
        $this->runMigrations();
        
        // 清理缓存
        $this->clearCache();
    }
    
    /**
     * 执行数据库迁移
     * 
     * @return void
     * @throws RuntimeException
     */
    protected function runMigrations(): void
    {
        $thinkPath = $this->rootPath . 'think';
        
        if (!file_exists($thinkPath)) {
            return;
        }
        
        // 先检查 migrate 命令是否可用
        exec('php ' . escapeshellarg($thinkPath) . ' list 2>&1', $listOutput, $listCode);
        $commandList = implode("\n", $listOutput);
        
        // 如果 migrate:run 命令不存在，跳过迁移
        if (strpos($commandList, 'migrate:run') === false) {
            trace('migrate:run 命令不可用，跳过数据库迁移', 'info');
            return;
        }
        
        exec('php ' . escapeshellarg($thinkPath) . ' migrate:run 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            $errorMsg = !empty($output) ? implode("\n", $output) : '未知错误 (返回码: ' . $returnCode . ')';
            throw new RuntimeException('数据库迁移失败: ' . $errorMsg);
        }
    }
    
    /**
     * 清理缓存
     * 
     * @return void
     */
    protected function clearCache(): void
    {
        $thinkPath = $this->rootPath . 'think';
        
        if (file_exists($thinkPath)) {
            exec('php ' . escapeshellarg($thinkPath) . ' clear 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                trace('清理缓存失败: ' . implode("\n", $output), 'warning');
            }
        }
    }
    
    /**
     * 更新版本文件
     * 
     * @param string $version 新版本号
     * @return void
     */
    public function updateVersionFile(string $version): void
    {
        $versionFile = $this->rootPath . 'config/version.php';
        $content = "<?php\n// 此文件由更新系统自动维护，请勿手动修改\nreturn [\n    'version'    => '{$version}',\n    'updated_at' => '" . date('Y-m-d H:i:s') . "',\n];\n";
        file_put_contents($versionFile, $content);
    }
    
    /**
     * 回滚到备份
     * 
     * @param string $backupDir 备份目录
     * @return void
     */
    public function rollback(string $backupDir): void
    {
        if (!is_dir($backupDir)) {
            return;
        }
        
        $this->copyDirectory($backupDir, $this->rootPath);
    }
    
    /**
     * 复制目录
     * 
     * @param string $source 源目录
     * @param string $target 目标目录
     * @return void
     */
    public function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $targetPath = $target . '/' . $iterator->getSubPathname();
            
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                $dir = dirname($targetPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                copy($item->getPathname(), $targetPath);
            }
        }
    }
    
    /**
     * 清理临时文件
     * 
     * @param string $path 要清理的目录
     * @return void
     */
    public function cleanup(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        
        @rmdir($path);
    }
    
    /**
     * 设置排除路径
     * 
     * @param array $paths 排除路径列表
     * @return void
     */
    public function setExcludePaths(array $paths): void
    {
        $this->excludePaths = $paths;
    }
    
    /**
     * 获取排除路径
     * 
     * @return array
     */
    public function getExcludePaths(): array
    {
        return $this->excludePaths;
    }
}

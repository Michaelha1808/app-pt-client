module.exports = {
  apps: [
    {
      name: 'caloeye',
      script: '/var/www/app/scripts/start.sh',
      interpreter: 'bash',
      autorestart: true,
      watch: false,
      // Cho containers đủ thời gian shutdown sạch trước khi PM2 kill
      kill_timeout: 30000,
    },
  ],
}

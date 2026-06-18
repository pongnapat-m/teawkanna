FROM php:8.2-apache

# สั่งติดตั้งโมดูล mysqli เข้าไปใน OS ตรงๆ
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# คัดลอกโค้ดทั้งหมดในโปรเจกต์เข้าตู้คอนเทนเนอร์
COPY . /var/www/html/

# เปิดสิทธิ์ให้ระบบเข้าถึงไฟล์ได้ตามปกติ
RUN chown -R www-data:www-data /var/www/html

FROM nginx:1.10
COPY nginx-config /etc/nginx/conf.d/default.conf
COPY . /var/www/html/timings

# Szablon danych serwera dla deploy.ps1. Skopiuj do deploy/targets.local.psd1 (plik jest w .gitignore)
# i uzupełnij. Nie commituj targets.local.psd1.
#   Remote – konto SSH: uzytkownik@host
#   Port   – port SSH (liczba, bez cudzysłowów)
#   Domain – domena aplikacji = nazwa katalogu na serwerze: ~/domains/<Domain>
#            (musi być równa hostowi z APP_BASE_URL w shared/.env.local, inaczej deploy.sh odmówi działania)
#   Php    – wersja PHP bez kropki: CLI /usr/local/bin/php<Php> i handler x-httpd-php<Php> w .htaccess
@{
    prod = @{
        Remote = 'uzytkownik@sNNN.cyber-folks.pl'
        Port   = 222
        Domain = 'monitor.example.com'
        Php    = '85'
    }
    # Opcjonalnie drugi serwer (np. testowy):
    # staging = @{ Remote = '...'; Port = 222; Domain = '...'; Php = '85' }
}

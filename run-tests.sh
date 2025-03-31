#!/bin/bash

# Script para ejecutar pruebas del paquete Azure Blob Storage

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Función para verificar si Azurite está en ejecución
check_azurite() {
    nc -z localhost 10000 > /dev/null 2>&1
    return $?
}

# Mostrar ayuda
show_help() {
    echo -e "${BLUE}Script para ejecutar pruebas de Azure Blob Storage${NC}"
    echo ""
    echo "Uso:"
    echo "  ./run-tests.sh [opción]"
    echo ""
    echo "Opciones:"
    echo "  --all         Ejecutar todas las pruebas (unitarias e integración)"
    echo "  --unit        Ejecutar solo pruebas unitarias"
    echo "  --integration Ejecutar pruebas de integración (requiere Azurite)"
    echo "  --coverage    Generar reporte de cobertura"
    echo "  --large       Ejecutar pruebas de archivos grandes"
    echo "  --help        Mostrar esta ayuda"
    echo ""
}

# Si no hay argumentos, mostrar ayuda
if [ $# -eq 0 ]; then
    show_help
    exit 0
fi

case "$1" in
    --all)
        # Verificar si Azurite está ejecutándose
        if ! check_azurite; then
            echo -e "${YELLOW}Advertencia: Azurite no está en ejecución. Las pruebas de integración fallarán.${NC}"
            echo -e "Puedes iniciar Azurite con: ${GREEN}azurite --silent --location ./azurite --debug ./debug.log${NC}"
            echo ""
            read -p "¿Deseas continuar de todos modos? (s/n): " continue_anyway
            if [[ ! $continue_anyway =~ ^[Ss]$ ]]; then
                exit 1
            fi
        fi

        echo -e "${BLUE}Ejecutando todas las pruebas...${NC}"
        vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php tests
        ;;

    --unit)
        echo -e "${BLUE}Ejecutando pruebas unitarias...${NC}"
        vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php --exclude-group integration,large-files tests
        ;;

    --integration)
        # Verificar si Azurite está ejecutándose
        if ! check_azurite; then
            echo -e "${RED}Error: Azurite no está en ejecución.${NC}"
            echo -e "Puedes iniciar Azurite con: ${GREEN}azurite --silent --location ./azurite --debug ./debug.log${NC}"
            exit 1
        fi

        echo -e "${BLUE}Ejecutando pruebas de integración...${NC}"
        vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php --group integration tests
        ;;

    --large)
        # Verificar si Azurite está ejecutándose
        if ! check_azurite; then
            echo -e "${RED}Error: Azurite no está en ejecución.${NC}"
            echo -e "Puedes iniciar Azurite con: ${GREEN}azurite --silent --location ./azurite --debug ./debug.log${NC}"
            exit 1
        fi

        echo -e "${BLUE}Ejecutando pruebas de archivos grandes...${NC}"
        vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php --group large-files tests
        ;;

    --coverage)
        echo -e "${BLUE}Generando reporte de cobertura...${NC}"
        vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php --coverage-html ./coverage --exclude-group integration,large-files tests
        echo -e "${GREEN}Reporte generado en: ./coverage/index.html${NC}"
        ;;

    --help)
        show_help
        ;;

    *)
        echo -e "${RED}Opción no reconocida: $1${NC}"
        show_help
        exit 1
        ;;
esac
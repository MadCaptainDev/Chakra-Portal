import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Poppins', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    50: '#F2F9FC',
                    100: '#E4F2F7',
                    200: '#ABDAE7',
                    300: '#8ACCE0',
                    400: '#67BCD4',
                    500: '#4FA9C4',
                    600: '#3D8CA6',
                    700: '#2F6E84',
                    800: '#284250',
                    900: '#132A38',
                },
            },
        },
    },

    plugins: [forms],
};

module.exports = function(grunt) {
    grunt.initConfig({
        sass: {
            // this is the "dev" Sass config used with "grunt watch" command
            dev: {
                options: {
                    style: 'expanded',
                    // tell Sass to look in the Bootstrap stylesheets directory when compiling
                    loadPath: 'node_modules/bootstrap-sass/assets/stylesheets'
                },
                files: {
                    // the first path is the output and the second is the input
                    'css/main.css': 'scss/main.scss'
                }
            },
            // this is the "production" Sass config used with the "grunt buildprod" command
            dist: {
                options: {
                    style: 'compressed',
                    loadPath: 'node_modules/bootstrap-sass/assets/stylesheets'
                },
                files: {
                    'css/main.css': 'scss/main.scss'
                }
            }
        },
        // configure the "grunt watch" task
        watch: {
            sass: {
                files: 'scss/*.scss',
                tasks: ['sass:dev']
            }
        }
    });
    grunt.loadNpmTasks('grunt-contrib-sass');
    grunt.loadNpmTasks('grunt-contrib-watch');

    // "grunt buildprod" is the same as running "grunt sass:dist".
    // if I had other tasks, I could add them to this array.
    grunt.registerTask('buildprod', ['sass:dist']);
    grunt.registerTask('build', ['sass:dev']);
};
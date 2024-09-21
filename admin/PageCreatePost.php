<?php
namespace GpcDev\Admin;

class PageCreatePost
{
    public function __construct()
    {
        add_action( 'admin_menu', array($this, 'register_menu') );

        add_action( 'wp_ajax_gpc_create_posts', [$this, 'gpc_create_posts'] );
        add_action( 'wp_ajax_gpc_get_post_type_taxonomies', [$this, 'gpc_get_post_type_taxonomies'] );
    }

    public function register_menu()
    {
        // create top level submenu page which point to main menu page
        $hook = add_submenu_page(
            'gpc-dev-general', // parent slug
            'Tạo bài viết', // Page title
            'Tạo bài viết', // Menu title
            'manage_options', // capability
            'gpc-dev-create-posts', // menu slug
            array($this, 'settings_page') // callback
        );

        add_filter("gpc_dev_admin_pages", function($pages) use ($hook) {
            $pages[] = $hook;
            return $pages;
        });
    }

    public function gpc_get_post_type_taxonomies()
    {
        $post_type = $_POST['type'];
        $taxonomies = get_object_taxonomies($post_type, 'objects');

        $results = [];
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => $taxonomy->name,
                'hide_empty' => false,
            ]);

            if (empty($terms)) {
                continue;
            }

            $tax = [
                'name' => $taxonomy->name,
                'label' => $taxonomy->label,
                'terms' => [],
            ];

            if ( $taxonomy->hierarchical ) {
                $tax['terms'] = $this->sort_terms_hierarchically($terms);
            } else {
                $tax['terms'] = array_map(function($term) {
                    return [
                        'name' => $term->name,
                        'term_id' => $term->term_id,
                        'level' => 0,
                    ];
                }, $terms);
            }

            $results[] = $tax;
        }

        wp_send_json_success(['taxonomies' => $results]);
    }

    private function sort_terms_hierarchically( &$terms, $parent_id = 0, $level = 0 )
    {
        $results = [];
        foreach ( $terms as $index => $term ) {
            if ( $term->parent == $parent_id ) {
                $results[] = [
                    'name' => $term->name,
                    'term_id' => $term->term_id,
                    'level' => $level,
                ];
                unset( $terms[ $index ] );
                $results = array_merge($results, $this->sort_terms_hierarchically( $terms, $term->term_id, $level + 1 ));
            }
        }

        return $results;
    }

    public function gpc_create_posts()
    {
        $list = $_POST['list'];
        $results = [];

        foreach ($list as $group) {
            $post_type = $group['type'];
            $is_acf = is_string($group['is_acf']) ?
                filter_var($group['is_acf'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) :
                (bool) $group['is_acf'];

            foreach ($group['posts'] as $item) {
                $results[] = $this->insert_post($post_type, $item, $is_acf);
            }
        }

        wp_send_json_success(['message' => 'Các bài viết đã được tạo: ' . implode(', ', $results)]);
    }

    private function insert_post($post_type, $post_data, $is_acf)
    {
        $title = $post_data['name'];
        $terms = $post_data['terms'];
        $fields = $post_data['fields'];
        $images = $post_data['images'];

        $tax_input = [];
        foreach ($terms as $key => $value) {
            foreach ($value as $term_id) {
                $tax_input[$key][] = intval($term_id);
            }
        }

        $args = [
            'post_title' => $title,
            'post_type' => $post_type,
            'post_status' => 'publish',
            'tax_input' => $tax_input,
        ];

        $post_id = wp_insert_post($args);

        if(!is_wp_error($post_id)) {
            $this->update_post_meta($post_id, $fields, $is_acf);

            if (!empty($images)) {
                set_post_thumbnail($post_id, $images[0]['id']);
            }
        }

        return $post_id;
    }

    private function update_post_meta($post_id, $fields, $is_acf = false)
    {
        if (empty($fields)) {
            return;
        }

        foreach ($fields as $key => $value) {
            if ($is_acf) {
                update_field($key, $value, $post_id);
            } else {
                update_post_meta($post_id, $key, $value);
            }
        }
    }

    public function settings_page()
    {
        $builtin = [
            'post' => [
                'label' => __('Post'),
                'name' => 'post',
                'taxonomies' => [],
            ],
            'page' => [
                'label' => __('Page'),
                'name' => 'page',
                'taxonomies' => [],
            ],
        ];

        $post_types = get_post_types([
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            '_builtin' => false,
        ], 'objects');

        $post_types = array_merge($builtin, array_map(function($item) {
            return [
                'name' => $item->name,
                'label' => $item->label,
                'taxonomies' => [],
            ];
        }, $post_types));
    ?>
        <div class="wrap" x-data="app">
            <h1>Tạo bài viết</h1>

            <!-- Thêm nhóm -->
            <div class="card mb3 max-w-full">
                <div class="gpc-form grid grid-cols-2 gap-4">
                    <section>
                        <div class="form-group">
                            <label>Loại bài viết</label>
                            <select x-model="newGroup.type">
                                <option value="">Chọn loại bài viết</option>
                                <template x-for="item in postTypes">
                                    <option :value="item.name" x-text="item.label + ' (' + item.name + ')'"></option>
                                </template>
                            </select>
                            <span class="error-text" x-cloak x-show="newGroupError.type" x-text="newGroupError.type"></span>
                        </div>

                        <div class="form-group">
                            <label>Danh sách bài viết</label>
                            <textarea name="terms" rows="10" x-model="newGroup.posts"></textarea>
                            <span class="help">Mỗi bài viết một hàng</span>
                            <span class="error-text" x-cloak x-show="newGroupError.posts" x-text="newGroupError.posts"></span>
                        </div>
                    </section>
                    <section>
                        <div class="form-group">
                            <label>Custom Fields</label>
                            <textarea name="terms" rows="5" x-model="newGroup.fields"></textarea>
                            <span class="help">Mỗi field một hàng</span>
                            <span class="help">Nếu dùng <mark><b>Carbon fields</b></mark> thì <mark>thêm gạch dưới ở trước tên field</mark>, ví dụ: <mark>_carbon_field</mark></span>
                            <span class="help">Nếu field là <mark><b>select</b></mark> thì có dạng: <mark>ten_field|option1,option2,option3</mark></span>
                        </div>

                        <div class="form-group">
                            <label></label>
                            <label class="checkbox-label"><input type="checkbox" value="1" x-model="newGroup.is_acf" /> Custom fields dùng Advanced Custom Fields</label>
                        </div>
                    </section>
                </div>

                <div class="mt3">
                    <button class="button" @click="addGroup">Thêm</button>
                </div>
            </div>

            <!-- List nhóm -->
            <template x-for="(group, groupIndex) in groups">
                <div class="card mb3 max-w-full">
                    <h3>
                        <span x-text="group.label + ' (' + group.type + ')'"></span>
                        <a href="javascript:;" @click.prevent="removeGroup" style="text-decoration:none; color: #dc2626" data-tooltip="Xoá">
                            <span class="dashicons dashicons-trash"></span>
                        </a>
                    </h3>

                    <table class="wp-list-table widefat fixed striped table-view-list gpc-form">
                        <thead>
                            <tr>
                                <th style="width: 50px">STT</th>
                                <th>Bài viết</th>
                                <th x-show="postTypes[group.type].taxonomies.length">Phân loại</th>
                                <th x-show="group.fields.length">Custom fields <span x-text="group.is_acf ? '(ACF)' : ''"></span></th>
                                <th width="200"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(post, index) in group.posts">
                                <tr>
                                    <td x-text="index + 1"></td>
                                    <td class="space-y-2">
                                        <div>
                                            <label>Tiêu đề</label>
                                            <textarea x-model="post.name" class="w-full" rows="1" @input="resizeTextarea($el)"></textarea>
                                        </div>
                                        <div>
                                            <label>Tóm tắt</label>
                                            <textarea x-model="post.excerpt" class="w-full" rows="1" @input="resizeTextarea($el)"></textarea>
                                        </div>
                                        <div>
                                            <label>Hình đại diện</label>
                                            <div x-data="uploader" class="uploader" x-model="post.images" x-modelable="selectedImages">
                                                <div class="uploader-images" x-cloak x-show="selectedImages.length > 0">
                                                    <template x-for="(image, index) in selectedImages" :key="image.id">
                                                        <div class="uploader-images__item">
                                                            <img :src="image.url" class="uploader-images__img" />
                                                            <a href="javascript:;" class="uploader-images__remove" @click.prevent="removeImage(index)">
                                                                <span class="dashicons dashicons-no-alt"></span>
                                                            </a>
                                                        </div>
                                                    </template>
                                                </div>
                                                <div class="uploader-buttons">
                                                    <button class="button" @click="openUploader">Chọn hình</button>
                                                </div>
                                                <input type="hidden" class="uploader-values" value="" x-model="selectedImageIds">
                                            </div>
                                        </div>
                                    </td>
                                    <td x-show="postTypes[group.type].taxonomies.length" class="space-y-2">
                                        <span class="gpc-spinner" x-show="!postTypes[group.type].taxonomyLoaded"></span>
                                        <template x-for="tax in postTypes[group.type].taxonomies">
                                            <div>
                                                <label x-text="tax.label + ' (' + tax.name + ')'"></label>
                                                <select x-data="select2(post.terms[tax.name], tax.label, tax.terms)"
                                                    x-model="post.terms[tax.name]"
                                                    x-modelable="modelValue"
                                                    multiple
                                                >
                                                    <template x-for="term in options">
                                                        <option :value="term.term_id" x-text="'— '.repeat(term.level) + term.name"></option>
                                                    </template>
                                                </select>
                                            </div>
                                        </template>
                                    </td>
                                    <td x-show="group.fields.length" class="space-y-2">
                                        <template x-for="field in group.fields">
                                            <div>
                                                <label x-text="field.name"></label>
                                                <template x-if="field.type == 'text'">
                                                    <input type="text" x-model="post.fields[field.name]" class="w-full" />
                                                </template>
                                                <template x-if="field.type == 'select'">
                                                    <select x-model="post.fields[field.name]">
                                                        <option value="" x-text="`-- Chọn ${field.name} --`"></option>
                                                        <template x-for="option in field.options">
                                                            <option :value="option" x-text="option" :selected="post.fields[field.name] == option"></option>
                                                        </template>
                                                    </select>
                                                </template>
                                            </div>
                                        </template>
                                    </td>
                                    <td>
                                        <button class="button" @click="removePost(group, index)"><span class="dashicons dashicons-trash"></span> Xoá</button>
                                        <button class="button" @click="duplicatePost(group, index)"><span class="dashicons dashicons-admin-page"></span> Nhân bản</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <div class="mt3">
                        <button class="button" @click="addPost(group)">Thêm</button>
                    </div>
                </div>
            </template>

            <p class="gpc-submit">
                <button class="button button-primary" @click="submit" :disabled="loading">Lưu lại</button>
                <span class="spinner" :style="loading && { visibility: 'visible' }"></span>
            </p>

            <div class="mt3 gpc-notice is-dismissible is-success" x-show="message" x-cloak>
                <p x-text="message"></p>
            </div>

            <!-- <pre x-html="JSON.stringify(postTypes, undefined, 2)"></pre> -->
            <!-- <pre x-html="JSON.stringify(groups, undefined, 2)"></pre> -->
        </div>

        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('app', () => ({
                    postTypes: <?php echo json_encode($post_types); ?>,
                    newGroup: {},
                    newGroupError: {},
                    groups: [],
                    loading: false,
                    message: '',

                    init() {
                        this.newGroup = this.makeNewGroup();
                    },

                    makeNewGroup() {
                        return {
                            type: '',
                            label: '',
                            fields: '',
                            posts: '',
                            is_acf: false,
                        };
                    },

                    makeNewPost(data = {}) {
                        return Object.assign({
                            name: '',
                            excerpt: '',
                            fields: {},
                            terms: {},
                            images: [],
                        }, data);
                    },

                    addGroup() {
                        if (! this.newGroup.type) {
                            this.newGroupError.type = 'Vui lòng chọn loại bài viết';
                            return;
                        }

                        if (! this.newGroup.posts && ! this.newGroup.fields) {
                            this.newGroupError.posts = 'Vui lòng nhập nội dung hoặc custom fields';
                            return;
                        }

                        let selectedPostType = this.postTypes[this.newGroup.type];
                        this.loadPostTypeTaxonomies(selectedPostType);

                        let customFields = this.parseCustomFields(this.newGroup.fields)

                        let newPosts = this.arrayFromString(this.newGroup.posts)
                            .filter(x => x)
                            .map(x => this.makeNewPost({ name: x, }));

                        let existedGroup = this.groups.find(x => x.type === this.newGroup.type);
                        if (existedGroup) {
                            existedGroup.posts = existedGroup.posts.concat(newPosts);
                            let fields = this.parseCustomFields(this.newGroup.fields)
                                .filter(x => ! existedGroup.fields.find(y => y.name === x.name));
                            existedGroup.fields = existedGroup.fields.concat(fields);
                        } else {
                            this.groups.push({
                                type: this.newGroup.type,
                                label: selectedPostType.label,
                                is_acf: this.newGroup.is_acf,
                                fields: this.parseCustomFields(this.newGroup.fields),
                                posts: newPosts,
                            });
                        }

                        this.newGroup = this.makeNewGroup();
                        this.newGroupError = {};
                    },

                    removeGroup(groupIndex) {
                        this.groups.splice(groupIndex, 1);
                    },

                    addPost(group) {
                        group.posts.push(this.makeNewPost());
                    },

                    removePost(group, index) {
                        group.posts.splice(index, 1);
                    },

                    duplicatePost(group, index) {
                        let clone = JSON.parse(JSON.stringify(group.posts[index]));
                        group.posts = group.posts.concat([clone]);
                    },

                    loadPostTypeTaxonomies(postType) {
                        if (postType.taxonomyLoaded) {
                            return;
                        }

                        jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'gpc_get_post_type_taxonomies',
                            type: postType.name,
                        }).done(response => {
                            if (response.success) {
                                postType.taxonomies = response.data.taxonomies;
                                postType.taxonomyLoaded = true;
                            }
                        });
                    },

                    arrayFromString(str) {
                        let arr = str.split("\n");
                        return arr.map(x => x.trim()).filter(x => x);
                    },

                    parseCustomFields(str) {
                        let arr = str.split("\n");
                        return arr.filter(x => x.trim()).map(x => {
                            if (x.includes('|')) {
                                let parts = x.split('|');
                                return {
                                    name: parts[0].trim(),
                                    type: 'select',
                                    options: parts[1].trim().split(',').map(x => x.trim()).filter(x => x),
                                };
                            } else {
                                return {
                                    name: x.trim(),
                                    type: 'text',
                                };
                            }
                        });
                    },

                    submit() {
                        if (!this.groups.length) {
                            return;
                        }

                        let list = this.groups.map(x => ({
                            type: x.type,
                            is_acf: x.is_acf,
                            posts: x.posts.filter(x => x.name),
                        }));

                        const that = this;

                        this.loading = true;
                        jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'gpc_create_posts',
                            list: list
                        }, function(response) {
                            that.loading = false;
                            that.message = response.data.message;
                            that.groups = [];
                        })
                    },

                    resizeTextarea(el) {
                        el.style.height = el.style.minHeight;
                        el.style.height = el.scrollHeight + 'px';
                    },
                }));

                Alpine.data('select2', (initValue, label, options) => ({
                    modelValue: [],
                    select: null,
                    label: label,
                    initValue: initValue,
                    options: options,

                    init() {
                        const that = this;
                        this.select = new SlimSelect({
                            select: that.$el,
                            settings: {
                                placeholderText: '-- Chọn ' + that.label + ' --',
                            },
                            events: {
                                afterChange: (newVal) => {
                                    that.modelValue = that.select.getSelected();
                                }
                            }
                        });

                        //console.log(this.label, this.initValue);
                        this.$nextTick(() => { this.select.setSelected(this.initValue) });
                    },
                }))

                Alpine.data('uploader', () => ({
                    selectedImages: [],
                    selectedImageIds: null,

                    openUploader(e) {
                        const that = this;

                        const customUploader = wp.media({
                            title: 'Insert images', // modal window title
                            library : {
                                type : 'image'
                            },
                            button: {
                                text: 'Use these images' // button label text
                            },
                            multiple: true
                        }).on( 'select', function() { // it also has "open" and "close" events
                            const attachments = customUploader.state().get( 'selection' ).toJSON().map(x => ({
                                id: x.id,
                                url: x.url,
                            }));

                            that.selectedImages = attachments;
                            that.selectedImageIds = attachments.map(x => x.id).join(',');
                        })

                        // already selected images
                        customUploader.on( 'open', function() {

                            if( that.selectedImages.length ) {
                                const selection = customUploader.state().get( 'selection' );
                                that.selectedImages.forEach( function( image ) {
                                    const attachment = wp.media.attachment( image.id );
                                    attachment.fetch();
                                    selection.add( attachment ? [attachment] : [] );
                                } );
                            }

                        })

                        customUploader.open()
                    },

                    removeImage(index) {
                        this.selectedImages.splice(index, 1);
                        this.selectedImageIds = this.selectedImages.map(x => x.id).join(',');
                    }
                }))
            })


        </script>
    <?php
    }
}
<?php
namespace GpcDev\Admin;

class PageCreateProduct
{
    public function __construct()
    {
        add_action( 'admin_menu', array($this, 'register_menu') );

        add_action( 'wp_ajax_gpc_create_products', [$this, 'gpc_create_products'] );
    }

    public function register_menu()
    {
        // create top level submenu page which point to main menu page
        $hook = add_submenu_page(
            'gpc-dev-general', // parent slug
            'Tạo sản phẩm (Woo)', // Page title
            'Tạo sản phẩm (Woo)', // Menu title
            'manage_options', // capability
            'gpc-dev-create-products', // menu slug
            array($this, 'settings_page') // callback
        );

        add_filter("gpc_dev_admin_pages", function($pages) use ($hook) {
            $pages[] = $hook;
            return $pages;
        });
    }

    private function get_product_taxonomies()
    {
        $post_type = 'product';
        $taxonomies = get_object_taxonomies($post_type, 'objects');

        $results = [];
        foreach ($taxonomies as $taxonomy) {
            if (in_array($taxonomy->name, ['product_type', 'product_visibility'])) {
                continue;
            }

            $terms = get_terms([
                'taxonomy' => $taxonomy->name,
                'hide_empty' => false,
                'public' => true,
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

        return $results;
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

    public function gpc_create_products()
    {
        $list = $_POST['products'];
        $is_acf = $_POST['is_acf'];
        $results = [];

        foreach ($list as $product) {
            $results[] = $this->insert_product($product, $is_acf);
        }

        wp_send_json_success(['message' => 'Các sản phẩm đã được tạo: ' . implode(', ', $results)]);
    }

    private function insert_product($post_data, $is_acf)
    {
        $title = $post_data['name'];
        $excerpt = $post_data['excerpt'];
        $terms = $post_data['terms'];
        $fields = $post_data['fields'];
        $images = $post_data['images'];
        $price = $post_data['price'];
        $price_sale = $post_data['sale_price'];
        $sku = $post_data['sku'];

        $category_ids = isset($terms['product_cat']) ? $terms['product_cat'] : [];
        $tag_ids = isset($terms['product_tag']) ? $terms['product_tag'] : [];

        // that's CRUD object
        $product = new \WC_Product_Simple();

        $product->set_name( $title ); // product title
        $product->set_sku( $sku );
        $product->set_regular_price( $price ); // in current shop currency
        if ($price_sale > 0) {
            $product->set_sale_price( $price_sale );
        }
        $product->set_short_description( $excerpt );
        $product->set_category_ids( $category_ids );
        $product->set_tag_ids( $tag_ids );

        if (!empty($images)) {
            $imageIds = array_map(function($image) {
                return $image['id'];
            }, $images);

            $featured_image_id = array_shift( $imageIds );
            $product->set_image_id( $featured_image_id );
            $product->set_gallery_image_ids( $imageIds );
        }

        $product->save();

        $post_id = $product->get_id();

        if(!is_wp_error($post_id)) {
            $this->update_post_meta($post_id, $fields, $is_acf);
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
        $taxonomies = $this->get_product_taxonomies();
    ?>
        <div class="wrap card max-w-1/2 mb3">
            <h1>Import từ file csv</h1>
            <p>Download file data mẫu <a href="https://docs.google.com/spreadsheets/d/16Cp-OE1giuPyulQRaKb5lvKjVCnmu0d3PPYiHsUxL5o/edit?usp=sharing" target="_blank">ở đây</a> (Chọn <code>File -> Download -> .csv</code>)</p>
            <p>Sau đó thực hiện import bằng công cụ của Woocommerce (<code>Sản phẩm -> Tất cả sản phẩm -> Nhập vào</code>)</p>
            <p><mark>Giao diện trang quản lý của user thực hiện import phải là tiếng Việt</mark></p>
        </div>

        <div class="wrap" x-data="app">
            <h1>Tạo sản phẩm</h1>

            <!-- Thêm nhóm -->
            <div class="card mb3 max-w-full">
                <div class="gpc-form grid grid-cols-2 gap-4">
                    <section>
                        <div class="form-group">
                            <label>Danh sách sản phẩm</label>
                            <textarea name="terms" rows="10" x-model="newGroup.products"></textarea>
                            <span class="help">Mỗi sản phẩm một hàng</span>
                            <span class="help">Nếu có giá thì có dạng <mark>ten_sp|gia</mark></span>
                            <span class="help">Nếu có giá khuyến mãi thì có dạng <mark>ten_sp|gia;gia_km</mark> (giá cách nhau bởi dấu chấm phẩy)</span>
                            <span class="error-text" x-cloak x-show="newGroupError.products" x-text="newGroupError.products"></span>
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
            <div class="card mb3 max-w-full" x-cloak x-show="list.length">
                <table class="wp-list-table widefat fixed striped table-view-list gpc-form">
                    <thead>
                        <tr>
                            <th style="width: 50px">STT</th>
                            <th>Sản phẩm</th>
                            <th style="width: 200px">Giá</th>
                            <th style="width: 200px">Phân loại</th>
                            <th x-show="customFields.length">Custom fields <span x-text="is_acf ? '(ACF)' : ''"></span></th>
                            <th width="200"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(product, index) in list">
                            <tr>
                                <td x-text="index + 1"></td>
                                <td class="space-y-2">
                                    <div>
                                        <label>Tiêu đề</label>
                                        <textarea x-model="product.name" class="w-full" rows="1" @input="resizeTextarea($el)"></textarea>
                                    </div>
                                    <div>
                                        <label>SKU</label>
                                        <input type="text" x-model="product.sku" />
                                    </div>
                                    <div>
                                        <label>Tóm tắt</label>
                                        <textarea x-model="product.excerpt" class="w-full" rows="1" @input="resizeTextarea($el)"></textarea>
                                    </div>
                                    <div>
                                        <label>Hình sản phẩm</label>
                                        <div x-data="uploader" class="uploader" x-model="product.images" x-modelable="selectedImages">
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
                                        <span class="help">Hình đầu tiên làm hình đại diện, các hình còn lại làm gallery</span>
                                    </div>
                                </td>
                                <td class="space-y-2">
                                    <div>
                                        <label>Giá</label>
                                        <input type="text" x-model="product.price" class="w-full" />
                                    </div>
                                    <div>
                                        <label>Giá sale</label>
                                        <input type="text" x-model="product.sale_price" class="w-full" />
                                    </div>
                                </td>
                                <td class="space-y-2">
                                    <template x-for="tax in taxonomies">
                                        <div>
                                            <label x-text="tax.label + ' (' + tax.name + ')'"></label>
                                            <select x-data="select2(product.terms[tax.name], tax.label, tax.terms)"
                                                x-model="product.terms[tax.name]"
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
                                <td x-show="customFields.length" class="space-y-2">
                                    <template x-for="field in customFields">
                                        <div>
                                            <label x-text="field.name"></label>
                                            <template x-if="field.type == 'text'">
                                                <input type="text" x-model="product.fields[field.name]" class="w-full" />
                                            </template>
                                            <template x-if="field.type == 'select'">
                                                <select x-model="product.fields[field.name]">
                                                    <option value="" x-text="`-- Chọn ${field.name} --`"></option>
                                                    <template x-for="option in field.options">
                                                        <option :value="option" x-text="option" :selected="product.fields[field.name] == option"></option>
                                                    </template>
                                                </select>
                                            </template>
                                        </div>
                                    </template>
                                </td>
                                <td>
                                    <button class="button" @click="removeProduct(index)"><span class="dashicons dashicons-trash"></span> Xoá</button>
                                    <button class="button" @click="duplicateProduct(index)"><span class="dashicons dashicons-admin-page"></span> Nhân bản</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div class="mt3">
                    <button class="button" @click="addProduct()">Thêm</button>
                </div>
            </div>

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
                    taxonomies: <?php echo json_encode($taxonomies); ?>,
                    newGroup: {},
                    newGroupError: {},
                    list: [],
                    customFields: [],
                    loading: false,
                    message: '',
                    is_acf: false,

                    init() {
                        this.newGroup = this.makeNewGroup();
                    },

                    makeNewGroup() {
                        return {
                            fields: '',
                            is_acf: false,
                            products: '',
                        };
                    },

                    makeNewProduct(data = {}) {
                        return Object.assign({
                            name: '',
                            sku: '',
                            excerpt: '',
                            fields: {},
                            terms: {},
                            images: [],
                            price: 0,
                            sale_price: 0,
                        }, data);
                    },

                    addGroup() {

                        if (! this.newGroup.products && ! this.newGroup.fields) {
                            this.newGroupError.products = 'Vui lòng nhập nội dung hoặc custom fields';
                            return;
                        }

                        let customFields = this.parseCustomFields(this.newGroup.fields)
                            .filter(x => ! this.customFields.find(y => y.name === x.name));

                        this.customFields = this.customFields.concat(customFields);
                        this.is_acf = this.newGroup.is_acf;

                        let newProducts = this.parseProductsFromString(this.newGroup.products)
                            .map(x => this.makeNewProduct({
                                name: x.name,
                                price: x.price,
                                sale_price: x.sale_price
                            }));

                        this.list = this.list.concat(newProducts);

                        this.newGroup = this.makeNewGroup();
                        this.newGroupError = {};
                    },

                    addProduct() {
                        this.list.push(this.makeNewProduct());
                    },

                    removeProduct(index) {
                        this.list.splice(index, 1);
                    },

                    duplicateProduct(index) {
                        let clone = JSON.parse(JSON.stringify(this.list[index]));
                        this.list = this.list.concat([clone]);
                    },

                    parseProductsFromString(str) {
                        let arr = str.split("\n");
                        return arr.filter(x => x.trim()).map(x => {
                            let parts = x.split('|');
                            let name = parts[0].trim();
                            let price = 0;
                            let sale_price = 0;

                            if (parts.length > 1) {
                                if (parts[1].includes(';')) {
                                    let parts2 = parts[1].split(';');
                                    price = parseFloat(parts2[0].trim());
                                    sale_price = parts2.length > 1 ? parseFloat(parts2[1].trim()) : 0;
                                } else {
                                    price = parseFloat(parts[1].trim());
                                }
                            }

                            return {
                                name: name,
                                price: price,
                                sale_price: sale_price,
                            };
                        });
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
                        if (!this.list.length) {
                            return;
                        }

                        let data = {
                            is_acf: this.is_acf,
                            products: this.list.filter(x => x.name),
                        }

                        const that = this;

                        this.loading = true;
                        jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'gpc_create_products',
                            ...data
                        }, function(response) {
                            that.loading = false;
                            that.message = response.data.message;
                            that.list = [];
                            that.is_acf = false;
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
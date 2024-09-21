<?php
namespace GpcDev\Admin;

class PageCreateTerm
{
    public function __construct()
    {
        add_action( 'admin_menu', array($this, 'register_menu') );

        add_action( 'wp_ajax_gpc_create_terms', [$this, 'gpc_create_terms'] );
        add_action( 'wp_ajax_gpc_get_terms', [$this, 'gpc_get_terms'] );
    }

    public function register_menu()
    {
        // create top level submenu page which point to main menu page
        $hook = add_submenu_page(
            'gpc-dev-general', // parent slug
            'Tạo terms', // Page title
            'Tạo terms', // Menu title
            'manage_options', // capability
            'gpc-dev-create-terms', // menu slug
            array($this, 'settings_page') // callback
        );

        add_filter("gpc_dev_admin_pages", function($pages) use ($hook) {
            $pages[] = $hook;
            return $pages;
        });
    }

    public function gpc_get_terms()
    {
        $taxonomy = $_POST['taxonomy'];
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
        ]);

        $results = $this->sort_terms_hierarchically($terms);

        wp_send_json_success(['terms' => $results]);
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

    public function gpc_create_terms()
    {
        $list = $_POST['list'];
        $results = [];

        foreach ($list as $item) {
            $taxonomy = $item['taxonomy'];
            $terms = $item['terms'];
            $hierarchical = $item['hierarchical'];

            foreach ($terms as $term) {
                $args = [];
                if ($hierarchical && $term['parent']) {
                    $args['parent'] = $term['parent'];
                }

                $newTerm = wp_insert_term($term['name'], $taxonomy, $args);

                $results[] = $newTerm['term_id'];

                if (empty($term['children'])) {
                    continue;
                }

                foreach ($term['children'] as $child) {
                    $childArgs = ['parent' => $newTerm['term_id']];
                    $newChildTerm = wp_insert_term($child, $taxonomy, $childArgs);

                    $results[] = $newChildTerm['term_id'];
                }
            }
        }

        wp_send_json_success(['message' => 'Các term đã được tạo: ' . implode(', ', $results)]);
    }

    public function settings_page()
    {
        $taxonomies = get_taxonomies(['public' => true], 'objects');
        $taxonomies = array_map(function($taxonomy) {
            return [
                'name' => $taxonomy->name,
                'label' => $taxonomy->label,
                'hierarchical' => boolval($taxonomy->hierarchical),
            ];
        }, $taxonomies);
    ?>
        <div class="wrap" x-data="app">
            <h1>Tạo terms</h1>

            <!-- Thêm nhóm -->
            <div class="card mb3">
                <h2>Thêm nhóm</h2>

                <div class="gpc-form">
                    <div class="form-group">
                        <label for="taxonomy">Taxonomy</label>
                        <select x-model="newGroup.taxonomy">
                            <option value="">Chọn taxonomy</option>
                            <template x-for="item in taxonomies">
                                <option :value="item.name" x-text="item.label + ' (' + item.name + ')'"></option>
                            </template>
                        </select>
                        <span class="error-text" x-cloak x-show="newGroupError.taxonomy" x-text="newGroupError.taxonomy"></span>
                    </div>
                    <div class="form-group">
                        <label for="terms">Danh mục</label>
                        <textarea name="terms" rows="5" x-model="newGroup.terms"></textarea>
                        <span class="help">Mỗi mục một hàng hoặc phân cách bằng dấu phẩy</span>
                        <span class="error-text" x-cloak x-show="newGroupError.terms" x-text="newGroupError.terms"></span>
                    </div>
                    <div class="form-submit">
                        <button class="button" @click="addGroup">Thêm</button>
                    </div>
                </div>
            </div>

            <!-- List nhóm -->
            <template x-for="(item, groupIndex) in groups">
                <div class="card mb3 max-w-lg">
                    <h3><span x-text="item.label + ' (' + item.taxonomy + ')'"></span> <a href="javascript:;" @click.prevent="removeGroup" style="text-decoration:none; color: #dc2626"  data-tooltip="Xoá"><span class="dashicons dashicons-trash"></span></a></h3>

                    <table class="wp-list-table widefat fixed striped table-view-list" :style="!item.hierarchical && { maxWidth: '500px' }">
                        <thead>
                            <tr>
                                <th>Tên</th>
                                <th width="200" x-show="item.hierarchical">Parent</th>
                                <th width="300" x-show="item.hierarchical">Children <p class="description">Mỗi mục một hàng hoặc phân cách bằng dấu phẩy</p></th>
                                <th width="200"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, index) in item.terms">
                                <tr>
                                    <td>
                                        <input type="text" x-model="row.name" style="width:100%">
                                    </td>
                                    <td x-show="item.hierarchical">
                                        <select x-model="row.parent">
                                            <option value="" x-text="'-- Chọn ' + item.label + ' --'"></option>
                                            <template x-for="term in taxonomies[item.taxonomy].terms">
                                                <option :value="term.term_id" x-text="'— '.repeat(term.level) + term.name"></option>
                                            </template>
                                        </select>
                                    </td>
                                    <td x-show="item.hierarchical">
                                        <textarea class="w-full" rows="1" x-model="row.children" @input="$el.style.height = $el.style.minHeight; $el.style.height = $el.scrollHeight + 'px'"></textarea>
                                    </td>
                                    <td>
                                        <button class="button" @click="removeTerm(item, index)"><span class="dashicons dashicons-trash"></span> Xoá</button>
                                        <button class="button" @click="duplicateTerm(item, row)"><span class="dashicons dashicons-admin-page"></span> Nhân bản</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <div class="mt3">
                        <button class="button" @click="addTerm(item)">Thêm</button>
                    </div>
                </div>
            </template>


            <p class="gpc-submit">
                <button class="button button-primary" @click="submit()" :disabled="loading">Lưu lại</button>
                <span class="spinner" :style="loading && { visibility: 'visible' }"></span>
            </p>

            <div class="mt3 gpc-notice is-dismissible is-success" x-show="message" x-cloak>
                <p x-text="message"></p>
            </div>

            <!-- <pre x-html="JSON.stringify(taxonomies)"></pre>
            <pre x-html="JSON.stringify(groups)"></pre> -->
        </div>

        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('app', () => ({
                    taxonomies: <?php echo json_encode($taxonomies); ?>,
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
                            taxonomy: '',
                            hierarchical: false,
                            label: '',
                            terms: '',
                        };
                    },

                    makeNewTerm() {
                        return {
                            name: '',
                            children: '',
                            parent: '',
                        }
                    },

                    addGroup() {
                        if (! this.newGroup.taxonomy) {
                            this.newGroupError.taxonomy = 'Vui lòng chọn taxonomy';
                            return;
                        }

                        if (! this.newGroup.terms) {
                            this.newGroupError.terms = 'Vui lòng nhập nội dung';
                            return;
                        }

                        let selectedTaxonomy = this.taxonomies[this.newGroup.taxonomy];
                        if (! selectedTaxonomy.termLoaded) {
                            this.loadTerms(selectedTaxonomy);
                        }

                        let terms = this.arrayFromString(this.newGroup.terms);

                        terms = terms.filter(x => x).map(x => ({ name: x, children: '', parent: 0 }) );

                        let existedTaxonomy = this.groups.find(x => x.taxonomy === this.newGroup.taxonomy);
                        if (existedTaxonomy) {
                            existedTaxonomy.terms = existedTaxonomy.terms.concat(terms);
                        } else {
                            this.groups.push({
                                taxonomy: this.newGroup.taxonomy,
                                hierarchical: selectedTaxonomy.hierarchical,
                                label: selectedTaxonomy.label,
                                terms: terms,
                            });
                        }

                        this.newGroup = this.makeNewGroup();
                        this.newGroupError = {};
                    },

                    removeGroup(index) {
                        this.groups.splice(index, 1);
                    },

                    addTerm(item) {
                        item.terms.push(this.makeNewTerm());
                    },

                    removeTerm(item, index) {
                        item.terms.splice(index, 1);
                    },

                    duplicateTerm(item, row) {
                        let clone = Object.assign({}, row);
                        item.terms.push(clone);
                    },

                    loadTerms(taxonomy) {
                        if (taxonomy.termLoaded) {
                            return;
                        }

                        if (!taxonomy.hierarchical) {
                            taxonomy.termLoaded = true;
                            return;
                        }

                        jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'gpc_get_terms',
                            taxonomy: taxonomy.name
                        }, function(response) {
                            taxonomy.termLoaded = true;
                            taxonomy.terms = response.data.terms;
                        })
                    },

                    arrayFromString(str) {
                        let arr = [];
                        if (str.includes("\n")) {
                            arr = str.split("\n");
                        } else {
                            arr = str.split(",");
                        }

                        return arr.map(x => x.trim());
                    },

                    submit() {
                        if (!this.groups.length) {
                            return;
                        }

                        let data = this.groups.map((x) => {
                            return {
                                taxonomy: x.taxonomy,
                                hierarchical: x.hierarchical,
                                terms: x.terms.filter(y => y.name).map((y) => {
                                    return {
                                        name: y.name,
                                        children: y.children.trim() ? this.arrayFromString(y.children) : null,
                                        parent: y.parent,
                                    }
                                })
                            }
                        });

                        const that = this;
                        let list = this.groups.map((x) => {
                            return {
                                taxonomy: x.taxonomy,
                                hierarchical: x.hierarchical,
                                terms: x.terms.filter(y => y.name).map((y) => {
                                    return {
                                        name: y.name,
                                        children: y.children.trim() ? this.arrayFromString(y.children) : null,
                                        parent: y.parent,
                                    }
                                })
                            }
                        });

                        this.loading = true;
                        jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'gpc_create_terms',
                            list: list
                        }, function(response) {
                            that.loading = false;
                            that.message = response.data.message;
                            that.groups = [];

                            setTimeout(() => that.message = '', 5000)
                        })
                    }

                }))
            })
        </script>
    <?php
    }
}
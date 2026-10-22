export type RestaurantListing = {
    id: number;
    name: string;
    slug: string;
    address: string;
    table_count: number;
};

export type RestaurantDetails = RestaurantListing & {
    phone: string | null;
};

export type MenuDish = {
    id: number;
    name: string;
    description: string | null;
    price: string;
};

export type MenuCategory = {
    id: number;
    name: string;
    slug: string;
    dishes: MenuDish[];
};

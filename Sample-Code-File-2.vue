<template>
  <AppLayout>
    <Hero bg="bg-search-hero" height="h-[55vh]" width="w-1/2">
      <h1 class="site_search_homes_title text-4xl md:text-6xl">SEARCH HOMES</h1>
      <p class="site_search_homes_content">
        We dedicate ourselves to making your dreams come true. Whether you are
        looking for your next home or to learn the value of your neighborhood,
        you can find everything you need here.
      </p>
    </Hero>
    <SearchFilter />
    <div class="grid grid-cols-2 bg-blue-50 py-4 px-1">
      <div class="col-span-2 sm:col-span-1">
        <GoogleMap
          :lineCoordinates="state.lineCoordinates"
          :properties="state.property"
          :active="state.activeProperty"
          @hoverPin="hoverPin"
          @showCardItem="(item) => openModal(item)"
        />
      </div>
      <div
        class="col-span-2 h-[calc(100vh-82px)] overflow-y-auto sm:col-span-1 grid grid-cols-1 lg:grid-cols-2 gap-2 text-gray-700 px-2"
        id="property_list"
      >
        <div v-for="item in state.property" :key="item.id">
          <Card
            :item="item"
            @click="openModal(item)"
            :active="state.activeProperty"
            @mouseover="state.activeProperty = { ...item, passedByCard: true }"
            @mouseleave="state.activeProperty = {}"
          />
        </div>
        <span ref="loadMoreIntersect"></span>
      </div>
    </div>
    <ModalVue
      :open="open"
      clses="w-[90%] xl:w-[1200px] h-[94vh] relative max-sm:overflow-y-auto"
    >
      <span
        class="text-xl absolute -right-10 font-bold bg-black text-white px-3 pb-1 cursor-pointer rounded"
        @click="closeModal()"
      >
        x
      </span>
      <PropertyDetails :property_detail="state.property_detail" />
    </ModalVue>
  </AppLayout>
</template>

<script setup>
import AppLayout from "@/Layouts/AppLayout/Index.vue";
import Hero from "@/Components/Sections/HeroSection.vue";
import SearchFilter from "./SearchFilter.vue";
import GoogleMap from "./GoogleMap.vue";
import Card from "./Card.vue";
import ModalVue from "../../Components/Modals/Modal.vue";
import PropertyDetails from "./PropertyDetail/PropertyDetails.vue";
import axios from "axios";
import { onMounted, reactive, ref } from "@vue/runtime-core";

const open = ref(false);
const state = reactive({
  property_detail: {},
  activeProperty: {},
  property: [],
  lineCoordinates: []
});
const nextPageUrl = ref("search-records");
console.log("next-page...", nextPageUrl);
const loadMoreIntersect = ref(null);

onMounted(async () => {
  await getSearchRecords();
  const observer = new IntersectionObserver((entries) =>
    entries.forEach((entry) => entry.isIntersecting && getSearchRecords(), {
      rootMargin: "-250px 0px 0px 0px",
    })
  );
  observer.observe(loadMoreIntersect.value);
});

async function getSearchRecords() {
  let urlParams = new URLSearchParams(window.location.search);
  const params = {};
  if (urlParams.has("query")) {
    params.query = urlParams.get("query");
  }
  if (urlParams.has("CITY")) {
    params.CITY = urlParams.get("CITY");
  }
  if (urlParams.has("POSTALCODE")) {
    params.POSTALCODE = urlParams.get("POSTALCODE");
  }
  if (urlParams.has("STREETNAME")) {
    params.STREETNAME = urlParams.get("STREETNAME");
  }
  if (urlParams.has("STREETNUMBER")) {
    params.STREETNUMBER = urlParams.get("STREETNUMBER");
  }
  console.log("url-params....", params);
  if (nextPageUrl.value) {
    await axios
      .get(nextPageUrl.value, {
        params,
      })
      .then((res) => {
        state.property = [...state.property, ...res.data.data.data];
        nextPageUrl.value = res.data.data.next_page_url;
      });
  }
  let tmp = [];
  if(params.CITY){
    tmp = params.CITY.split(" ");
  }
  else if(params.STREETNAME){
    tmp = params.STREETNAME.split(" ");
  }
  else if(params.POSTALCODE){
    tmp = params.POSTALCODE.split(" ");
  }
  
  for (let i = 0; i < tmp.length; i++) {
    console.log('STREETNAME---',params, tmp);
    await axios
    .get("/get-line-coordiantes", {
      params : {
        town: tmp[i]
      },
    })
    .then((resp) => {
      if(resp.data.length){
        state.lineCoordinates = resp.data.map(item => ({ lat:parseFloat(item.lat), lng:parseFloat(item.lng) }) );
        console.log("lineCoordinates...", state.lineCoordinates);
      }
    });
  }
}
function openModal(item) {
  state.property_detail = item;
  open.value = true;
}
function closeModal() {
  open.value = false;
}
function hoverPin(item) {
  state.activeProperty = item;
}
</script>
